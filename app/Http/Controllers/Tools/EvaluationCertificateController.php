<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Document;
use App\Models\EvaluationCertificate;
use App\Models\Property;
use App\Models\Scopes\ContactScope;
use App\Models\User;
use App\Services\AgentSignatureService;
use App\Services\CandidatePractitionerService;
use App\Services\ContactDuplicateService;
use App\Services\EvaluationAuthorisationService;
use App\Support\Impersonation;
use App\Support\WhatsAppNumberFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
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

    /**
     * Download filename built from the property ADDRESS, sanitised to a safe slug —
     * e.g. "380-Wilfred-Street-Shelly-Beach-Margate-Evaluation-Certificate.pdf".
     * Falls back to the ref/id when the certificate has no address.
     */
    private function certificateFilename(EvaluationCertificate $certificate): string
    {
        $address = trim((string) $certificate->address);
        if ($address === '') {
            return 'Evaluation-Certificate-EC-' . ($certificate->id ?? 'DRAFT') . '.pdf';
        }

        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $address);   // non-alphanumeric → hyphen
        $slug = trim((string) $slug, '-');
        $slug = substr($slug, 0, 120);                            // keep the filename sane

        return ($slug !== '' ? $slug : 'Evaluation-Certificate-EC-' . $certificate->id) . '-Evaluation-Certificate.pdf';
    }

    /**
     * File the signed certificate PDF onto the linked property's document drive
     * (spec item 4) using the canonical Document + document_properties mechanism —
     * the same pivot the PDF splitter / DR2 filing and Property::documents() use, so
     * the certificate appears on the property's drive exactly like any other filed
     * document. Idempotent (one Document per certificate); no-op when the certificate
     * is not linked to a property or has no filed PDF yet.
     */
    private function fileToPropertyDrive(EvaluationCertificate $certificate, User $actor): void
    {
        if (! $certificate->property_id || ! $certificate->signed_pdf_path) {
            return;
        }

        // The cert's property link is authoritative — resolve within the agency,
        // bypassing the viewer's personal visibility scope (Non-Negotiable #7 keeps
        // it inside the agency), never crossing an agency boundary.
        $property = Property::withoutGlobalScopes()
            ->where('agency_id', $certificate->agency_id)
            ->find($certificate->property_id);
        if (! $property) {
            return;
        }

        // Never file the same certificate twice.
        if (Document::where('source_type', 'eval_cert')->where('source_id', $certificate->id)->exists()) {
            return;
        }

        $doc = Document::create([
            'agency_id'        => $certificate->agency_id,
            'original_name'    => $this->certificateFilename($certificate),
            'storage_path'     => $certificate->signed_pdf_path,
            'disk'             => config('filesystems.default', 'local'),
            'mime_type'        => 'application/pdf',
            'size'             => Storage::exists($certificate->signed_pdf_path) ? Storage::size($certificate->signed_pdf_path) : null,
            'document_type_id' => null,
            'source_type'      => 'eval_cert',
            'source_id'        => $certificate->id,
            'uploaded_by'      => $actor->id,
        ]);

        $doc->properties()->syncWithoutDetaching([$property->id]);
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

        $filename = $this->certificateFilename($certificate);
        $inline   = $request->boolean('inline');

        if ($certificate->signed_pdf_path && Storage::exists($certificate->signed_pdf_path)) {
            return $inline
                ? Storage::response($certificate->signed_pdf_path, $filename, ['Content-Disposition' => 'inline; filename="' . $filename . '"'])
                : Storage::download($certificate->signed_pdf_path, $filename);
        }

        $pdf = $this->previewPdf($certificate);

        return $inline ? $pdf->stream($filename) : $pdf->download($filename);
    }

    /**
     * The live PREVIEW render (draft / pending — no filed artifact yet). Paints the
     * CANDIDATE's captured signature into "Evaluated & signed by" once submitted, so a
     * pending certificate reviews as the candidate actually signed it (the authoriser's
     * mark appears after they sign). Relations set so the names resolve on any render
     * context (incl. the public/no-auth path).
     */
    private function previewPdf(EvaluationCertificate $certificate): \Barryvdh\DomPDF\PDF
    {
        $certificate->setRelation('contact', $this->linkedContact($certificate));
        if ($certificate->signed_by_user_id) {
            $certificate->setRelation('signedBy', User::withoutGlobalScopes()->find($certificate->signed_by_user_id));
        }
        if ($certificate->authorised_by_user_id) {
            $certificate->setRelation('authorisedBy', User::withoutGlobalScopes()->find($certificate->authorised_by_user_id));
        }

        return $this->renderCertificatePdf($certificate, $certificate->candidate_signature_image, null);
    }

    /**
     * Render the certificate to a dompdf PDF using the same engine/options as
     * SignaturePdfService (Barryvdh dompdf). Deliberately NOT routed through
     * SignaturePdfService itself — that service is SignatureTemplate-bound
     * (docuperfect) and must not be repurposed here (no docuperfect regression).
     *
     * SIGNATURE-BLOCK SLOTS (cc1 Phase-4): pass the saved-signature PNG data-URIs
     * (data:image/png;base64,...) to bake an immutable signed artifact at sign time.
     *   $signatureImage           → "Evaluated & signed by" slot (direct full-status
     *                               signer, OR the candidate in the candidate flow).
     *   $authoriserSignatureImage → "Authorised by" slot (the full-status authoriser,
     *                               candidate flow only).
     * Both null on the unsigned preview path.
     */
    public function renderCertificatePdf(EvaluationCertificate $certificate, ?string $signatureImage = null, ?string $authoriserSignatureImage = null): \Barryvdh\DomPDF\PDF
    {
        $pdf = Pdf::loadView('tools.evaluation-certificate.pdf', [
            'certificate'              => $certificate,
            'signatureImage'           => $signatureImage,
            'authoriserSignatureImage' => $authoriserSignatureImage,
            'logoData'                 => $this->agencyLogoData($certificate),
            'showAuthoriser'           => $this->showsAuthoriser($certificate),
        ])->setPaper('a4', 'portrait');
        $pdf->setOption('isRemoteEnabled', true);

        return $pdf;
    }

    /**
     * The agency logo as a base64 data-URI for the certificate header — embedded,
     * NOT a remote URL, so the dompdf render is self-contained and fast (no network
     * fetch). Raster only (png/jpg/gif); returns null for missing/svg logos, in
     * which case the header falls back to the agency name text.
     */
    private function agencyLogoData(EvaluationCertificate $certificate): ?string
    {
        $path = $certificate->agency?->logo_path;
        if (! $path) {
            return null;
        }

        $abs = storage_path('app/public/' . ltrim($path, '/'));
        if (! is_file($abs)) {
            return null;
        }

        $mime = [
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        ][strtolower(pathinfo($abs, PATHINFO_EXTENSION))] ?? null;
        if (! $mime) {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($abs));
    }

    /**
     * Whether the "Authorised by" block renders. Only the CANDIDATE flow needs a
     * full-status authoriser: show it when the certificate has an authoriser OR its
     * creator is a candidate practitioner. A full-status practitioner signing their
     * own certificate directly needs no authoriser — hide it entirely. Determined
     * from the certificate's own data (not the live session) so it is correct on the
     * public/client render too, where there is no authenticated user.
     */
    private function showsAuthoriser(EvaluationCertificate $certificate): bool
    {
        if ($certificate->authorised_by_user_id) {
            return true;
        }

        $creator = User::withoutGlobalScopes()->find($certificate->created_by_user_id);

        return $creator !== null
            && strtolower(trim((string) $creator->designation)) === 'candidate property practitioner';
    }

    /**
     * Common signer guards: permission, agency isolation, NO impersonation, saved
     * signature configured. Returns the authenticated user. Used by every PIN action.
     */
    private function guardSigner(Request $request, EvaluationCertificate $certificate, AgentSignatureService $signatures): User
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('access_calculators'), 403);
        abort_unless((int) $certificate->agency_id === (int) ($user->effectiveAgencyId() ?? 0), 404);
        // A switch-user/impersonation session may NEVER place or unlock a saved signature.
        abort_if(Impersonation::actingAdminId() !== null, 403, 'Saved signatures are unavailable while acting as another user.');
        abort_unless($signatures->isConfigured($user), 422, 'Set up your saved signature and signing PIN in My Portal first.');

        return $user;
    }

    /**
     * Verify the signing PIN inline (or honour an unlock already held this session).
     * Returns a 422 JsonResponse on failure, or null when the signature is unlocked.
     */
    private function unlock(Request $request, User $user, AgentSignatureService $signatures, string $contextKey): ?JsonResponse
    {
        $pin = (string) $request->input('pin', '');
        if ($pin !== '') {
            if (! $signatures->verifyPinAndUnlock($user, $pin, $contextKey)) {
                return response()->json(['message' => 'Incorrect signing PIN.'], 422);
            }
        } elseif (! $signatures->isUnlocked($user, $contextKey)) {
            return response()->json(['message' => 'Enter your signing PIN to place your saved signature.'], 422);
        }

        return null;
    }

    /**
     * Bake the immutable authorised PDF (both signature slots), store it at
     * signed_pdf_path, and file it to the linked property's document drive. Sets the
     * status to authorised. The signedBy/authorisedBy/contact relations must already
     * be set on $certificate so the render shows the right names.
     */
    private function finaliseCertificate(EvaluationCertificate $certificate, ?string $signerImage, ?string $authoriserImage, User $actor): void
    {
        $certificate->setRelation('contact', $this->linkedContact($certificate));
        $certificate->status = EvaluationCertificate::STATUS_AUTHORISED;

        $pdf  = $this->renderCertificatePdf($certificate, $signerImage, $authoriserImage);
        $path = 'evaluation-certificates/' . (int) $certificate->agency_id . '/' . $certificate->id . '-signed.pdf';
        Storage::put($path, $pdf->output());
        $certificate->signed_pdf_path = $path;

        $certificate->save();

        // Non-fatal: a filing hiccup must never fail the signature (spec item 4).
        try {
            $this->fileToPropertyDrive($certificate, $actor);
        } catch (\Throwable $e) {
            Log::warning('eval-cert: property-drive filing failed', ['certificate' => $certificate->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Full-status DIRECT sign — a full-status practitioner finalising their OWN
     * certificate with no candidate authorisation. Their signature is baked into
     * "Evaluated & signed by"; the certificate becomes authorised and is filed to the
     * property drive. Candidates cannot finalise — they submit for authorisation.
     */
    public function sign(Request $request, EvaluationCertificate $certificate, AgentSignatureService $signatures, CandidatePractitionerService $practitioners): JsonResponse
    {
        $user = $this->guardSigner($request, $certificate, $signatures);
        abort_if($practitioners->isCandidate($user), 403, 'A candidate practitioner cannot finalise — sign to submit for authorisation instead.');
        abort_if($certificate->isAuthorised(), 409, 'This evaluation certificate is already signed.');
        abort_if($certificate->isPendingAuthorisation(), 409, 'This certificate is awaiting authorisation — use Authorise.');

        $contextKey = 'evalcert:' . $certificate->id;
        if (($err = $this->unlock($request, $user, $signatures, $contextKey)) !== null) {
            return $err;
        }

        $certificate->signed_by_user_id = $user->id;
        $certificate->setRelation('signedBy', $user);
        $this->finaliseCertificate($certificate, $signatures->image($user, 'signature', $contextKey), null, $user);
        $signatures->lock($user, $contextKey);

        return response()->json([
            'ok'           => true,
            'status'       => $certificate->status,
            'signed_by'    => $user->name,
            'download_url' => route('tools.cma.evaluation.download', $certificate),
        ]);
    }

    /**
     * Candidate SUBMIT — a candidate practitioner PIN-signs their part and queues the
     * certificate for a full-status practitioner to authorise. Their signature is
     * snapshotted now (encrypted) so it can be baked into "Evaluated & signed by" at
     * authorisation, when the candidate is no longer present to unlock it. NOT a
     * finalising signature — status becomes pending_authorisation.
     */
    public function submitForAuthorisation(Request $request, EvaluationCertificate $certificate, AgentSignatureService $signatures, CandidatePractitionerService $practitioners): JsonResponse
    {
        $user = $this->guardSigner($request, $certificate, $signatures);
        abort_unless($practitioners->isCandidate($user), 403, 'Only a candidate practitioner submits for authorisation; full-status practitioners sign directly.');
        abort_unless((int) $certificate->created_by_user_id === (int) $user->id, 403, 'You can only submit your own evaluation.');
        abort_unless($certificate->isDraft() || $certificate->isRejected(), 409, 'This certificate is not awaiting your submission.');

        $contextKey = 'evalcert:' . $certificate->id;
        if (($err = $this->unlock($request, $user, $signatures, $contextKey)) !== null) {
            return $err;
        }

        $certificate->candidate_signature_image = $signatures->image($user, 'signature', $contextKey);
        $certificate->signed_by_user_id     = $user->id;   // the candidate evaluated + signed
        $certificate->authorised_by_user_id = null;
        $certificate->reject_note           = null;
        $certificate->status                = EvaluationCertificate::STATUS_PENDING_AUTHORISATION;
        $certificate->save();

        $signatures->lock($user, $contextKey);

        // Make it findable: alert every eligible authoriser (bell notification) and
        // bust their sidebar-badge cache. Non-fatal — a notification hiccup must never
        // fail the submission.
        try {
            $this->notifyAuthorisers($certificate, $user, $practitioners);
        } catch (\Throwable $e) {
            Log::warning('eval-cert: authoriser notification failed', ['certificate' => $certificate->id, 'error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true, 'status' => $certificate->status]);
    }

    /**
     * Notify every eligible authoriser that a candidate certificate awaits them — a
     * bell notification linking straight to the evaluation screen (where the queue
     * lives), and a sidebar-badge cache bust so their count updates immediately.
     */
    private function notifyAuthorisers(EvaluationCertificate $certificate, User $candidate, CandidatePractitionerService $practitioners): void
    {
        $authorisers = $practitioners->getEligibleAuthorisers($candidate);   // throws if none → caught by caller
        $auth = app(EvaluationAuthorisationService::class);
        $url  = route('tools.cma.evaluation.authorisations');

        foreach ($authorisers as $authoriser) {
            DatabaseNotification::create([
                'id'              => (string) Str::uuid(),
                'type'            => 'evalcert.authorisation_pending',
                'notifiable_type' => User::class,
                'notifiable_id'   => $authoriser->id,
                'data'            => [
                    'title'          => 'Evaluation awaiting your authorisation',
                    'message'        => $candidate->name . ' submitted an evaluation certificate for '
                                        . ($certificate->address ?: 'a property') . ' — review to authorise or reject.',
                    'action_url'     => $url,
                    'certificate_id' => $certificate->id,
                ],
            ]);
            $auth->forget($authoriser);
        }
    }

    /**
     * Full-status AUTHORISE — a full-status practitioner (or a BM/admin able to
     * authorise for the candidate) accepts a pending certificate and PIN-signs it.
     * Bakes the candidate's snapshotted signature into "Evaluated & signed by" and the
     * authoriser's live signature into "Authorised by", producing the filed artifact.
     */
    public function authorise(Request $request, EvaluationCertificate $certificate, AgentSignatureService $signatures, CandidatePractitionerService $practitioners): JsonResponse
    {
        $user = $this->guardSigner($request, $certificate, $signatures);
        abort_if($practitioners->isCandidate($user), 403, 'A candidate practitioner cannot authorise a certificate.');
        abort_unless($certificate->isPendingAuthorisation(), 409, 'This certificate is not awaiting authorisation.');

        $candidate = User::withoutGlobalScopes()->find($certificate->signed_by_user_id ?: $certificate->created_by_user_id);
        abort_unless($candidate && $practitioners->canAuthoriseFor($user, $candidate), 403, 'You are not an eligible authoriser for this candidate.');

        $contextKey = 'evalcert:' . $certificate->id;
        if (($err = $this->unlock($request, $user, $signatures, $contextKey)) !== null) {
            return $err;
        }

        $certificate->authorised_by_user_id = $user->id;
        $certificate->setRelation('signedBy', $candidate);   // Evaluated & signed by = candidate
        $certificate->setRelation('authorisedBy', $user);    // Authorised by = full-status
        $this->finaliseCertificate(
            $certificate,
            $certificate->candidate_signature_image,                 // candidate's snapshot
            $signatures->image($user, 'signature', $contextKey),     // authoriser's live signature
            $user
        );
        $signatures->lock($user, $contextKey);

        return response()->json([
            'ok'            => true,
            'status'        => $certificate->status,
            'authorised_by' => $user->name,
            'download_url'  => route('tools.cma.evaluation.download', $certificate),
        ]);
    }

    /** Full-status REJECT — send the pending certificate back to the candidate with a note. */
    public function reject(Request $request, EvaluationCertificate $certificate, CandidatePractitionerService $practitioners): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('access_calculators'), 403);
        abort_unless((int) $certificate->agency_id === (int) ($user->effectiveAgencyId() ?? 0), 404);
        abort_if($practitioners->isCandidate($user), 403, 'A candidate practitioner cannot reject a certificate.');
        abort_unless($certificate->isPendingAuthorisation(), 409, 'This certificate is not awaiting authorisation.');

        $candidate = User::withoutGlobalScopes()->find($certificate->signed_by_user_id ?: $certificate->created_by_user_id);
        abort_unless($candidate && $practitioners->canAuthoriseFor($user, $candidate), 403, 'You are not an eligible authoriser for this candidate.');

        $note = trim((string) $request->input('note', ''));
        abort_if($note === '', 422, 'Add a note telling the candidate what to fix.');

        $certificate->status      = EvaluationCertificate::STATUS_REJECTED;
        $certificate->reject_note = $note;
        $certificate->save();

        return response()->json(['ok' => true, 'status' => $certificate->status]);
    }

    /**
     * The candidate-authorisation queue for /tools/cma, scoped to the viewer:
     *   • a candidate sees THEIR OWN submitted certs (pending / authorised / rejected);
     *   • a full-status practitioner sees the certs PENDING authorisation that they are
     *     eligible to authorise (canAuthoriseFor the candidate creator).
     */
    public function queue(Request $request, CandidatePractitionerService $practitioners): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('access_calculators'), 403);
        $agencyId = (int) ($user->effectiveAgencyId() ?? 0);
        $isCandidate = $practitioners->isCandidate($user);

        if ($isCandidate) {
            $certs = EvaluationCertificate::where('agency_id', $agencyId)
                ->where('created_by_user_id', $user->id)
                ->whereIn('status', [
                    EvaluationCertificate::STATUS_PENDING_AUTHORISATION,
                    EvaluationCertificate::STATUS_AUTHORISED,
                    EvaluationCertificate::STATUS_REJECTED,
                ])
                ->latest()->limit(50)->get();
        } else {
            $certs = app(EvaluationAuthorisationService::class)->pendingFor($user);
        }

        return response()->json([
            'role'  => $isCandidate ? 'candidate' : 'authoriser',
            'items' => $certs->map(fn (EvaluationCertificate $c) => $this->queueItem($c))->all(),
        ]);
    }

    /** One queue row — enough to populate the form + show status without a second fetch. */
    private function queueItem(EvaluationCertificate $certificate): array
    {
        $creator = User::withoutGlobalScopes()->find($certificate->created_by_user_id);
        $contact = $this->linkedContact($certificate);

        return [
            'id'                     => $certificate->id,
            'address'                => $certificate->address,
            'property_type'          => $certificate->property_type,
            'analysis_date'          => optional($certificate->analysis_date)->format('Y-m-d'),
            'estimated_market_value' => $certificate->estimated_market_value,
            'bedrooms'               => $certificate->bedrooms,
            'bathrooms'              => $certificate->bathrooms,
            'parking'                => $certificate->parking,
            'key_features'           => $certificate->key_features,
            'property_id'            => $certificate->property_id,
            'contact'                => $contact ? ['id' => $contact->id, 'name' => $contact->full_name, 'phone' => $contact->phone] : null,
            'status'                 => $certificate->status,
            'reject_note'            => $certificate->reject_note,
            'candidate_name'         => $creator?->name,
            'submitted_at'           => optional($certificate->updated_at)->format('Y-m-d H:i'),
            'is_signed'              => $certificate->isAuthorised(),
            'download_url'           => route('tools.cma.evaluation.download', $certificate),
        ];
    }

    /**
     * The dedicated Pending Authorisations screen for full-status practitioners — a
     * LIST of certificates awaiting their authorisation, each opening to a READ-ONLY
     * review (the finished PDF) → Authorise & sign / Reject. Deliberately NOT the
     * create/edit builder — an authoriser signs a submitted cert, never edits it.
     */
    public function authorisations(Request $request): \Illuminate\Contracts\View\View
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('access_calculators'), 403);

        return view('tools.evaluation-certificate.authorisations', [
            'savedSigConfigured' => app(AgentSignatureService::class)->isConfigured($user),
        ]);
    }

    /**
     * The candidate's "My Evaluations" screen — a LIST of their submitted evaluations
     * (pending / authorised / rejected), each opening to a READ-ONLY view of the
     * finished certificate with Download / Print / Share (and Edit & resubmit when
     * returned). Mirrors the authorisations screen: viewing a submitted cert never
     * lands in the editable create/edit builder.
     */
    public function mine(Request $request): \Illuminate\Contracts\View\View
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('access_calculators'), 403);

        return view('tools.evaluation-certificate.mine');
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
        $filename = $this->certificateFilename($certificate);

        if ($certificate->signed_pdf_path && Storage::exists($certificate->signed_pdf_path)) {
            return Storage::response($certificate->signed_pdf_path, $filename, ['Content-Disposition' => 'inline; filename="' . $filename . '"']);
        }

        return $this->previewPdf($certificate)->stream($filename);
    }
}
