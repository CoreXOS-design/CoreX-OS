<?php

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureAdoption;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignaturePlaceGeneration;
use App\Models\Docuperfect\SignatureRequest;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * esign-signing-modes.md §4 / §5 (Build step 4, Mode 2 — adopt, then click to stamp).
 *
 * A signer who has adopted their signature and initial stamps it onto a place by clicking it. The IMAGE always comes
 * from the signer's own adoption on the server — a click only says WHERE — so a client can never stamp anything but the
 * mark the signer agreed to. Each click is one append-only place generation (method stamped_click, which adoption it
 * came from, server time, IP, user agent) plus one audit row. Removing a stamped place supersedes its generation without
 * a successor; clicking again writes the next generation. Nothing is edited or deleted.
 *
 * Applies to recipients who sign by link on a web (CDS) document in an adoption mode — the same population
 * SignatureAdoptionService::required() decides.
 */
class SignaturePlacementService
{
    /** "{party}-sig-{n}" / "{party}-init-{n}" — the keys the signing page gives its places. */
    public const KEY_PATTERN = '/^([a-z][a-z0-9_]{0,40})-(sig|init)-(\d{1,3})$/';

    public function __construct(
        private SignatureAdoptionService $adoptions,
        private SignaturePlaceGenerationRecorder $recorder,
        private CanonicalInkComposer $composer,
    ) {
    }

    /** @return array{party:string,kind:string,index:int}|null */
    public function parseKey(string $placeKey): ?array
    {
        if (! preg_match(self::KEY_PATTERN, $placeKey, $m)) {
            return null;
        }

        return [
            'party' => $m[1],
            'kind' => $m[2] === 'init' ? SignaturePlaceGeneration::KIND_INITIAL : SignaturePlaceGeneration::KIND_SIGNATURE,
            'index' => (int) $m[3],
        ];
    }

    /** The web (CDS) document this signer places marks on, or null when the signing page has no place-by-place ceremony. */
    public function webDocument(SignatureRequest $req): ?Document
    {
        $req->loadMissing('template.document.template');
        $document = $req->template?->document;
        if (! $document) {
            return null;
        }
        $docTemplate = $document->template;
        $data = is_array($document->web_template_data) ? $document->web_template_data : [];

        return empty($data['flattened_page_count']) && $docTemplate && $docTemplate->render_type === 'web' && $docTemplate->blade_view
            ? $document
            : null;
    }

    /**
     * Stamp one place with the signer's adopted mark.
     *
     * @param array{method?:string,fill_all_event_id?:int|null} $how
     * @return SignaturePlaceGeneration|null the generation written, or null when this exact mark was already there
     * @throws DomainException           not required / not adopted / no web document
     * @throws InvalidArgumentException  not a place key, or a place that is not this signer's party
     */
    public function place(SignatureRequest $req, string $placeKey, Request $http, array $how = []): ?SignaturePlaceGeneration
    {
        [$document, $adoption, $parsed] = $this->preflight($req, $placeKey);

        $image = $parsed['kind'] === SignaturePlaceGeneration::KIND_INITIAL ? $adoption->initial_image : $adoption->signature_image;
        $method = $how['method'] ?? SignaturePlaceGeneration::METHOD_STAMPED_CLICK;

        $written = $this->recorder->record($document, $req, [$placeKey => $image], $this->priorMarks($document, $req), $http, [
            'method' => $method,
            'adoption_id' => $adoption->id,
            'fill_all_event_id' => $how['fill_all_event_id'] ?? null,
        ]);

        $mine = collect($written)->first(fn (SignaturePlaceGeneration $g) => (int) $g->signature_request_id === (int) $req->id);
        if ($mine) {
            $this->audit($req, 'signature_placed', $http, [
                'place_key' => $placeKey,
                'kind' => $parsed['kind'],
                'method' => $method,
                'generation' => $mine->generation,
                'adoption_id' => $adoption->id,
                'adoption_generation' => $adoption->generation,
                'disclaimer_version' => $adoption->disclaimer_version,
                'fill_all_event_id' => $how['fill_all_event_id'] ?? null,
            ]);
        }

        return $mine;
    }

    /**
     * Take a stamped place back. The generation is stamped superseded (no successor) and the removal is audited;
     * clicking the place again writes the next generation. Only the signer's own stamped mark, and only before they
     * have finished signing.
     *
     * @return bool false when there was nothing of theirs to remove
     */
    public function remove(SignatureRequest $req, string $placeKey, Request $http): bool
    {
        [$document, , $parsed] = $this->preflight($req, $placeKey, requireAdoption: false);
        if ($req->status === SignatureRequest::STATUS_COMPLETED) {
            throw new DomainException('You have already finished signing this document.');
        }

        return DB::transaction(function () use ($req, $document, $placeKey, $parsed, $http) {
            $latest = SignaturePlaceGeneration::forPlace($document->id, $placeKey)->orderByDesc('generation')->lockForUpdate()->first();
            if (! $latest || $latest->superseded_at !== null || (int) $latest->signature_request_id !== (int) $req->id
                || ! in_array($latest->method, [SignaturePlaceGeneration::METHOD_STAMPED_CLICK, SignaturePlaceGeneration::METHOD_STAMPED_AUTO], true)) {
                return false;
            }

            $latest->update(['superseded_at' => now()]);
            $this->audit($req, 'signature_place_removed', $http, [
                'place_key' => $placeKey,
                'kind' => $parsed['kind'],
                'generation' => $latest->generation,
                'method' => $latest->method,
                'adoption_id' => $latest->adoption_id,
            ]);

            return true;
        });
    }

    /**
     * completeWeb in an adoption mode: every signature / initial image posted must be one the signer adopted (any
     * generation of their own adoptions) — a client cannot post an arbitrary image. DB-marker captures
     * ("marker-{id}") travel their own route and are not place stamps.
     *
     * @param array<string,mixed> $posted place key => data URI
     * @return string[] the keys whose image is not one of this signer's adopted marks
     */
    public function unadoptedMarks(SignatureRequest $req, array $posted): array
    {
        $sigHashes = [];
        $initHashes = [];
        SignatureAdoption::where('signature_request_id', $req->id)->get(['signature_image_hash', 'initial_image_hash'])
            ->each(function (SignatureAdoption $a) use (&$sigHashes, &$initHashes) {
                $sigHashes[$a->signature_image_hash] = true;
                $initHashes[$a->initial_image_hash] = true;
            });

        $bad = [];
        foreach ($posted as $key => $image) {
            if (! is_string($image) || trim($image) === '' || str_starts_with((string) $key, 'marker-')) {
                continue;
            }
            $parsed = $this->parseKey((string) $key);
            $hash = hash('sha256', $image);
            $ok = $parsed !== null && ($parsed['kind'] === SignaturePlaceGeneration::KIND_INITIAL ? isset($initHashes[$hash]) : isset($sigHashes[$hash]));
            if (! $ok) {
                $bad[] = (string) $key;
            }
        }

        return $bad;
    }

    /**
     * completeWeb in an adoption mode: make sure each posted mark has its place generation. A click whose request was
     * lost on a bad connection has none yet — it is recorded now as a click (the signer did click it; the server just
     * had not heard). A place already recorded by /place or /place-all is left exactly as it is.
     *
     * @param array<string,mixed> $posted
     * @param array<string,mixed> $prior  what the document's JSON map held before this completion
     */
    public function reconcile(Document $document, SignatureRequest $req, array $posted, array $prior, Request $http): void
    {
        $adoptions = SignatureAdoption::where('signature_request_id', $req->id)->orderByDesc('generation')->get();

        foreach ($posted as $key => $image) {
            if (! is_string($image) || trim($image) === '' || str_starts_with((string) $key, 'marker-')) {
                continue;
            }
            $parsed = $this->parseKey((string) $key);
            if (! $parsed) {
                continue;
            }
            $hash = hash('sha256', $image);
            $adoption = $adoptions->first(fn (SignatureAdoption $a) => ($parsed['kind'] === SignaturePlaceGeneration::KIND_INITIAL ? $a->initial_image_hash : $a->signature_image_hash) === $hash);

            $written = $this->recorder->record($document, $req, [(string) $key => $image], $prior, $http, [
                'method' => SignaturePlaceGeneration::METHOD_STAMPED_CLICK,
                'adoption_id' => $adoption?->id,
            ]);
            foreach ($written as $gen) {
                if ((int) $gen->signature_request_id === (int) $req->id) {
                    $this->audit($req, 'signature_placed', $http, [
                        'place_key' => (string) $key,
                        'kind' => $parsed['kind'],
                        'method' => SignaturePlaceGeneration::METHOD_STAMPED_CLICK,
                        'generation' => $gen->generation,
                        'adoption_id' => $adoption?->id,
                        'reconciled_at_completion' => true,
                    ]);
                }
            }
        }
    }

    /** What the document's JSON map held for this signer's places before now (the recorder's legacy-original recovery). @return array<string,mixed> */
    public function priorMarks(Document $document, SignatureRequest $req): array
    {
        $data = is_array($document->web_template_data) ? $document->web_template_data : [];

        return array_merge(
            (array) ($data['signed_initials'][$req->canonicalPartyKey()] ?? []),
            (array) ($data['signatures'] ?? [])
        );
    }

    /**
     * @return array{0:Document,1:SignatureAdoption|null,2:array{party:string,kind:string,index:int}}
     */
    private function preflight(SignatureRequest $req, string $placeKey, bool $requireAdoption = true): array
    {
        if (! $this->adoptions->required($req)) {
            throw new DomainException('This document does not use an adopted signature.');
        }
        $adoption = $this->adoptions->current($req);
        if ($requireAdoption && ! $adoption) {
            throw new DomainException('Please adopt your signature and initial first.');
        }
        $document = $this->webDocument($req) ?? throw new DomainException('This document is signed by signing every place.');

        $parsed = $this->parseKey($placeKey);
        if (! $parsed || ! in_array($parsed['party'], $this->composer->partyTokensFor((string) $req->party_role), true)) {
            throw new InvalidArgumentException('That is not one of your signature places.');
        }

        return [$document, $adoption, $parsed];
    }

    /** @param array<string,mixed> $metadata */
    public function audit(SignatureRequest $req, string $action, Request $http, array $metadata): void
    {
        SignatureAuditLog::create([
            'signature_template_id' => $req->signature_template_id,
            'signature_request_id' => $req->id,
            'action' => $action,
            'actor_type' => SignatureAuditLog::ACTOR_SIGNER,
            'actor_name' => $req->signer_name,
            'actor_email' => $req->signer_email,
            'actor_ip_address' => $http->ip(),
            'actor_user_agent' => $http->userAgent(),
            'metadata_json' => $metadata,
        ]);
    }
}
