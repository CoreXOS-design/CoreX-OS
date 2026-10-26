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

    /** Most places one confirmation may fill — a ceiling on a tampered request, far above any real document. */
    public const FILL_ALL_MAX_PLACES = 400;

    /**
     * esign-signing-modes.md §4 (Mode 3, Build step 5) — ONE confirmation fills every signature and initial place that is
     * the signer's. One `fill_all_confirmed` audit row (who, when, how many, the hash of the place list) and one
     * place generation per place (method stamped_auto, pointing at that confirmation) with its own `signature_placed` row.
     *
     * The mark stamped is always the signer's adopted one; the request only names places. Each key must be the signer's
     * own party; the signature places can be no more than the document itself carries for this signer (the server counts
     * them from the canonical document by the same ownership rule the ink is baked by). Page-break initials are laid out
     * on the signer's screen, so their number cannot be counted here — they are bounded by the party check and the
     * ceiling above, and what is baked onto the document is decided by ownership in the document, not by these keys.
     * Condition initials, amendment initials and disclosure answers have their own endpoints and are never in this list.
     *
     * @param string[] $placeKeys
     * @return array{event_id:int,count:int,placed:string[],already:string[]}
     * @throws DomainException               not Mode 3 / not adopted / no web document
     * @throws FillAllUnavailableException   the server cannot vouch for the list — sign each place instead
     * @throws InvalidArgumentException      an empty, oversized or foreign list
     */
    public function fillAll(SignatureRequest $req, array $placeKeys, int $oneByOneRemaining, Request $http): array
    {
        [$document, $adoption] = $this->preflightRequest($req);
        if ($this->adoptions->modeOf($req) !== \App\Models\Docuperfect\EsignSettings::MODE_ADOPT_AND_FILL_ALL) {
            throw new DomainException('This document is signed by clicking each place.');
        }

        $keys = array_values(array_unique(array_map('strval', $placeKeys)));
        if ($keys === [] || count($keys) > self::FILL_ALL_MAX_PLACES) {
            throw new InvalidArgumentException('There are no places to fill.');
        }
        $signatureKeys = 0;
        $initialKeys = 0;
        foreach ($keys as $key) {
            $parsed = $this->parseOwnKey($req, $key);
            $parsed['kind'] === SignaturePlaceGeneration::KIND_INITIAL ? $initialKeys++ : $signatureKeys++;
        }

        if ($signatureKeys > $this->ownedSignaturePlaces($req, $document)) {
            throw new FillAllUnavailableException('This document cannot be filled in automatically. Please click each place to sign it.');
        }

        sort($keys);

        return DB::transaction(function () use ($req, $adoption, $keys, $signatureKeys, $initialKeys, $oneByOneRemaining, $http) {
            $event = $this->audit($req, 'fill_all_confirmed', $http, [
                'adoption_id' => $adoption->id,
                'adoption_generation' => $adoption->generation,
                'disclaimer_version' => $adoption->disclaimer_version,
                'count_filled' => count($keys),
                'count_signatures' => $signatureKeys,
                'count_initials' => $initialKeys,
                'left_for_one_by_one' => max(0, $oneByOneRemaining),
                'place_list_hash' => hash('sha256', implode("\n", $keys)),
            ]);

            $placed = [];
            $already = [];
            foreach ($keys as $key) {
                $generation = $this->place($req, $key, $http, [
                    'method' => SignaturePlaceGeneration::METHOD_STAMPED_AUTO,
                    'fill_all_event_id' => $event->id,
                ]);
                if ($generation) {
                    $placed[] = $key;
                } else {
                    $already[] = $key;
                }
            }

            return ['event_id' => (int) $event->id, 'count' => count($keys), 'placed' => $placed, 'already' => $already];
        });
    }

    /** How many signature places the canonical document carries for this signer (0 when it cannot be told). */
    private function ownedSignaturePlaces(SignatureRequest $req, Document $document): int
    {
        $data = is_array($document->web_template_data) ? $document->web_template_data : [];
        $html = (string) ($data['canonical_html'] ?? '');
        if (trim($html) === '') {
            $html = app(CanonicalDocumentRenderer::class)->compose($req->template);
        }
        $sole = $req->template->requests()->where('party_role', $req->party_role)->count() === 1;

        return $this->composer->countOwnedMarkers($html, $req, 'signature', $sole);
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
        [$document, $adoption] = $this->preflightRequest($req, $requireAdoption);

        return [$document, $adoption, $this->parseOwnKey($req, $placeKey)];
    }

    /** @return array{0:Document,1:SignatureAdoption|null} */
    private function preflightRequest(SignatureRequest $req, bool $requireAdoption = true): array
    {
        if (! $this->adoptions->required($req)) {
            throw new DomainException('This document does not use an adopted signature.');
        }
        $adoption = $this->adoptions->current($req);
        if ($requireAdoption && ! $adoption) {
            throw new DomainException('Please adopt your signature and initial first.');
        }
        $document = $this->webDocument($req) ?? throw new DomainException('This document is signed by signing every place.');

        return [$document, $adoption];
    }

    /** @return array{party:string,kind:string,index:int} */
    private function parseOwnKey(SignatureRequest $req, string $placeKey): array
    {
        $parsed = $this->parseKey($placeKey);
        if (! $parsed || ! in_array($parsed['party'], $this->composer->partyTokensFor((string) $req->party_role), true)) {
            throw new InvalidArgumentException('That is not one of your signature places.');
        }

        return $parsed;
    }

    /** @param array<string,mixed> $metadata */
    public function audit(SignatureRequest $req, string $action, Request $http, array $metadata): SignatureAuditLog
    {
        return SignatureAuditLog::create([
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
