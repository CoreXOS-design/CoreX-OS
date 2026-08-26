<?php

declare(strict_types=1);

namespace App\Services\SG;

use App\Events\Property\PropertySgDocumentSaved;
use App\Models\Property;
use App\Models\PropertySgDocument;
use App\Models\SgSearchCache;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 3j C1 — Surveyor General search proxy.
 *
 * Wraps the 3-step JSP flow at csg.dlrrd.gov.za/esio:
 *   1. GET  /esio/searchproperty.jsp        — establishes JSESSIONID
 *   2. POST /esio/listregdivisions.jsp      — yields the region code (regDivision)
 *   3. POST /esio/listdocument.jsp          — yields the document list
 *
 * Findings from Part A live HTTP test (Margate Erf 1219 Portion 0):
 *   - Form action is listregdivisions.jsp, NOT searchproperty.jsp.
 *   - Fields are office (numeric ID), rural (0=urban, 1=rural), Town,
 *     Erf (the parcel number), Portion, FarmName.
 *   - Province → numeric ID lookup is internal to this class.
 *   - listregdivisions returns a "regCode" button name (e.g. N0ET0199);
 *     listdocument needs that PLUS the alpha office code (e.g. SGPMB).
 *   - JSESSIONID is set; we share a cookie jar across the two POSTs.
 *   - TIF URLs go through /esio/viewTIFF?furl=...&office=SGPMB — no
 *     auth needed for the actual TIF download (cookies optional).
 *
 * Cache: SHA-256 of normalised query → 24h TTL row. Cached hits skip the
 * HTTP roundtrip entirely (and the rate limiter, per spec C3).
 *
 * Failure modes (SgSearchResult discriminates):
 *   - HTTP 5xx after one retry → errorMessage set
 *   - HTML parse failure       → parseError=true
 *   - empty result set         → ok() + documents=[]
 */
final class SgSearchService
{
    public const BASE_URL    = 'https://csg.dlrrd.gov.za/esio';
    public const CACHE_HOURS = 24;
    public const TIMEOUT     = 15;
    public const USER_AGENT  = 'CoreXOS/1.0 (real estate platform; respect@corexos.co.za)';

    /** Province friendly-name → SG numeric office ID. FS/NC both use 1. */
    private const PROVINCE_IDS = [
        'eastern cape'      => 8,
        'free state'        => 1,
        'gauteng'           => 4,
        'kwa-zulu natal'    => 3,
        'kwazulu natal'     => 3,
        'kzn'               => 3,
        'limpopo'           => 5,
        'mpumalanga'        => 6,
        'north west'        => 9,
        'northern cape'     => 1,
        'western cape'      => 2,
    ];

    /**
     * @param array{
     *   province: string,
     *   rural_urban?: string,
     *   town: string,
     *   parcel_number: string,
     *   portion?: string,
     *   farm_name?: ?string,
     * } $query
     */
    public function search(array $query): SgSearchResult
    {
        $normalised = $this->normaliseQuery($query);
        $hash = hash('sha256', json_encode($normalised, JSON_THROW_ON_ERROR));

        // ── 1. Cache lookup ─────────────────────────────────────────
        $cached = SgSearchCache::where('query_hash', $hash)
            ->where('expires_at', '>', now())
            ->first();
        if ($cached) {
            return new SgSearchResult(
                documents:     $cached->parsed_documents_json ?? [],
                fromCache:     true,
                fetchedAt:     $cached->fetched_at?->toDateTimeImmutable(),
                resolvedQuery: $normalised,
            );
        }

        // ── 2. Province → numeric office ID ─────────────────────────
        $officeId = self::PROVINCE_IDS[mb_strtolower($normalised['province'])] ?? null;
        if ($officeId === null) {
            return new SgSearchResult(
                documents: [],
                fromCache: false,
                fetchedAt: null,
                errorMessage: 'Unknown province: ' . $normalised['province'],
                resolvedQuery: $normalised,
            );
        }
        $rural = $normalised['rural_urban'] === 'rural' ? 1 : 0;

        // ── 3. HTTP flow (3 steps with shared cookie jar) ───────────
        try {
            $jar = new CookieJar();
            $client = new GuzzleClient([
                'cookies' => $jar,
                'timeout' => self::TIMEOUT,
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept'     => 'text/html',
                ],
                'http_errors' => false,
            ]);

            // Step 1: GET the search form (establishes JSESSIONID).
            $resp1 = $client->get(self::BASE_URL . '/searchproperty.jsp');
            if ($resp1->getStatusCode() >= 500) {
                $resp1 = $client->get(self::BASE_URL . '/searchproperty.jsp');
            }
            if ($resp1->getStatusCode() >= 400) {
                return $this->errorResult('SG search form returned HTTP ' . $resp1->getStatusCode(), $normalised);
            }

            // Step 2: POST to list region divisions.
            $resp2 = $client->post(self::BASE_URL . '/listregdivisions.jsp', [
                'form_params' => [
                    'office'   => (string) $officeId,
                    'rural'    => (string) $rural,
                    'Town'     => $normalised['town'],
                    'Erf'      => $normalised['parcel_number'],
                    'Portion'  => $normalised['portion'],
                    'FarmName' => $normalised['farm_name'] ?? '',
                    'Submit'   => 'Search',
                ],
            ]);
            if ($resp2->getStatusCode() >= 500) {
                $resp2 = $client->post(self::BASE_URL . '/listregdivisions.jsp', [
                    'form_params' => [
                        'office'   => (string) $officeId,
                        'rural'    => (string) $rural,
                        'Town'     => $normalised['town'],
                        'Erf'      => $normalised['parcel_number'],
                        'Portion'  => $normalised['portion'],
                        'FarmName' => $normalised['farm_name'] ?? '',
                        'Submit'   => 'Search',
                    ],
                ]);
            }
            if ($resp2->getStatusCode() >= 400) {
                return $this->errorResult('SG listregdivisions returned HTTP ' . $resp2->getStatusCode(), $normalised);
            }

            $listHtml = (string) $resp2->getBody();
            [$regDivision, $officeAlpha] = $this->extractRegDivision($listHtml);
            if ($regDivision === null) {
                // Could be a legitimate "no results" — only the headers but
                // no data row means parcel not found in that town.
                $this->cacheResult($hash, $normalised, '', []);
                return new SgSearchResult(
                    documents: [],
                    fromCache: false,
                    fetchedAt: new \DateTimeImmutable(),
                    resolvedQuery: $normalised,
                );
            }

            // Step 3: POST to list documents.
            $resp3 = $client->post(self::BASE_URL . '/listdocument.jsp', [
                'form_params' => [
                    'office'      => $officeAlpha,
                    'Noffice'     => (string) $officeId,
                    'regDivision' => $regDivision,
                    'Erf'         => $normalised['parcel_number'],
                    'Portion'     => $normalised['portion'],
                    'FarmName'    => $normalised['farm_name'] ?? '',
                ],
            ]);
            if ($resp3->getStatusCode() >= 500) {
                $resp3 = $client->post(self::BASE_URL . '/listdocument.jsp', [
                    'form_params' => [
                        'office'      => $officeAlpha,
                        'Noffice'     => (string) $officeId,
                        'regDivision' => $regDivision,
                        'Erf'         => $normalised['parcel_number'],
                        'Portion'     => $normalised['portion'],
                        'FarmName'    => $normalised['farm_name'] ?? '',
                    ],
                ]);
            }
            if ($resp3->getStatusCode() >= 400) {
                return $this->errorResult('SG listdocument returned HTTP ' . $resp3->getStatusCode(), $normalised);
            }

            $docsHtml = (string) $resp3->getBody();
            try {
                $documents = $this->extractDocuments($docsHtml);
            } catch (\Throwable $e) {
                Log::warning('sg.parse_failed', ['error' => $e->getMessage(), 'query' => $normalised]);
                return new SgSearchResult(
                    documents: [],
                    fromCache: false,
                    fetchedAt: null,
                    parseError: true,
                    resolvedQuery: $normalised,
                );
            }

            $this->cacheResult($hash, $normalised, $docsHtml, $documents);

            return new SgSearchResult(
                documents:     $documents,
                fromCache:     false,
                fetchedAt:     new \DateTimeImmutable(),
                resolvedQuery: $normalised,
            );
        } catch (\Throwable $e) {
            Log::warning('sg.http_failed', ['error' => $e->getMessage(), 'query' => $normalised]);
            return $this->errorResult('SG service unreachable: ' . $e->getMessage(), $normalised);
        }
    }

    /**
     * Server-side download a single SG TIF to property drive storage.
     *
     * Idempotent: same sha256 + same property → returns existing row without
     * re-downloading.
     */
    public function fetchAndSaveTif(PropertySgDocument $sgDoc, User $by): PropertySgDocument
    {
        $url = $this->absolutiseSgUrl($sgDoc->sg_source_url);

        $client = new GuzzleClient([
            'timeout'     => self::TIMEOUT * 2,
            'headers'     => ['User-Agent' => self::USER_AGENT],
            'http_errors' => true,
        ]);

        $tmpPath = tempnam(sys_get_temp_dir(), 'sg_tif_');
        $client->request('GET', $url, ['sink' => $tmpPath]);

        $bytes = filesize($tmpPath) ?: 0;
        $sha   = hash_file('sha256', $tmpPath);

        // Idempotency — if any other SG doc for this property already has
        // this sha, point our row at the same storage and return.
        $twin = PropertySgDocument::where('property_id', $sgDoc->property_id)
            ->where('sha256', $sha)
            ->where('is_saved', true)
            ->whereNotNull('storage_path')
            ->where('id', '!=', $sgDoc->id)
            ->first();

        if ($twin) {
            @unlink($tmpPath);
            $sgDoc->forceFill([
                'storage_path'     => $twin->storage_path,
                'file_size_bytes'  => $twin->file_size_bytes,
                'mime_type'        => $twin->mime_type ?: 'image/tiff',
                'sha256'           => $sha,
                'is_saved'         => true,
                'saved_at'         => now(),
                'saved_by_user_id' => $by->id,
            ])->save();
            return $sgDoc->refresh();
        }

        $relative = sprintf(
            'properties/%d/%d/sg/%s_p%d.tif',
            $sgDoc->agency_id,
            $sgDoc->property_id,
            preg_replace('/[^A-Za-z0-9_\-]/', '_', $sgDoc->sg_document_number),
            $sgDoc->sg_page_number,
        );
        $written = Storage::disk($this->disk())->put($relative, file_get_contents($tmpPath));
        @unlink($tmpPath);

        if (!$written || !Storage::disk($this->disk())->exists($relative)) {
            throw new \RuntimeException("Failed to persist SG document to storage at {$relative}");
        }

        $sgDoc->forceFill([
            'storage_path'     => $relative,
            'file_size_bytes'  => $bytes,
            'mime_type'        => 'image/tiff',
            'sha256'           => $sha,
            'is_saved'         => true,
            'saved_at'         => now(),
            'saved_by_user_id' => $by->id,
        ])->save();

        try {
            event(new PropertySgDocumentSaved(
                propertyId:        (int) $sgDoc->property_id,
                sgDocumentId:      (int) $sgDoc->id,
                sgDocumentNumber:  (string) $sgDoc->sg_document_number,
                sgPageNumber:      (int) $sgDoc->sg_page_number,
                sgDocType:         (string) $sgDoc->sg_doc_type,
                fileSizeBytes:     (int) $bytes,
                agencyIdValue:     (int) $sgDoc->agency_id,
                actorUserIdValue:  (int) $by->id,
            ));
        } catch (\Throwable $e) {
            Log::warning('sg.event_dispatch_failed', ['id' => $sgDoc->id, 'error' => $e->getMessage()]);
        }

        return $sgDoc->refresh();
    }

    public function disk(): string
    {
        // Use the default Laravel filesystem; CoreX uses 'local' (storage/app)
        // in dev + 's3' (or equivalent) in production. Both are configured.
        return config('filesystems.default', 'local');
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function normaliseQuery(array $q): array
    {
        return [
            'province'      => trim((string) ($q['province'] ?? '')),
            'rural_urban'   => in_array(($q['rural_urban'] ?? 'urban'), ['rural', 'urban'], true)
                ? ($q['rural_urban'] ?? 'urban') : 'urban',
            'town'          => mb_strtolower(trim((string) ($q['town'] ?? ''))),
            'parcel_number' => trim((string) ($q['parcel_number'] ?? '')),
            'portion'       => trim((string) ($q['portion'] ?? '0')) ?: '0',
            'farm_name'     => trim((string) ($q['farm_name'] ?? '')) ?: null,
        ];
    }

    /**
     * Pull the regDivision code (e.g. "N0ET0199") + alpha office code
     * (e.g. "SGPMB") out of the listregdivisions response. Returns
     * [null, null] when no rows match (legitimate "not found").
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function extractRegDivision(string $html): array
    {
        $xpath = $this->loadHtml($html);

        // Alpha office code from the hidden input.
        $officeAlpha = null;
        $officeNodes = $xpath->query("//input[@name='office']");
        if ($officeNodes && $officeNodes->length > 0) {
            $officeAlpha = $officeNodes->item(0)->getAttribute('value') ?: null;
        }

        // Region division: the action button name on a data row. The form
        // has multiple <input type="hidden"> on the top and one
        // <input type="button"> per matching town with name=<regCode>.
        $regDivision = null;
        $buttons = $xpath->query("//input[@type='button']");
        if ($buttons) {
            foreach ($buttons as $btn) {
                /** @var \DOMElement $btn */
                $name = $btn->getAttribute('name');
                if ($name !== '' && preg_match('/^[A-Z0-9]{6,20}$/', $name)) {
                    $regDivision = $name;
                    break;
                }
            }
        }

        if ($regDivision === null || $officeAlpha === null) {
            return [null, null];
        }
        return [$regDivision, $officeAlpha];
    }

    private function loadHtml(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // SG site declares charset=ISO-8859-1; convert to UTF-8 so DOM doesn't
        // mangle multi-byte characters in town names.
        $utf8 = @mb_convert_encoding($html, 'HTML-ENTITIES', 'ISO-8859-1') ?: $html;
        $dom->loadHTML($utf8, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return new DOMXPath($dom);
    }

    /**
     * Parse listdocument.jsp response into an array of document descriptors.
     *
     * SG row shape (verified live):
     *   <TR><TD>7762/1949</TD><TD>1</TD><TD>GENERAL PLAN</TD><TD>
     *     <a href='./viewTIFF?furl=/images4/a0/105HVD01.TIF&office=SGPMB'>...</a></TD></TR>
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractDocuments(string $html): array
    {
        $xpath = $this->loadHtml($html);
        $documents = [];

        $rows = $xpath->query('//tr');
        if (!$rows) return $documents;

        foreach ($rows as $tr) {
            $tds = $xpath->query('./td', $tr);
            if (!$tds || $tds->length < 4) continue;

            $linkNodes = $xpath->query('./a', $tds->item(3));
            if (!$linkNodes || $linkNodes->length === 0) continue;

            /** @var \DOMElement $link */
            $link = $linkNodes->item(0);
            $href = $link->getAttribute('href');
            if (!str_contains($href, 'viewTIFF')) continue;

            $docNumber = trim($tds->item(0)->textContent);
            $pageNo    = trim($tds->item(1)->textContent);
            $docType   = trim($tds->item(2)->textContent);

            $documents[] = [
                'sg_document_number' => $docNumber,
                'sg_page_number'     => (int) ($pageNo ?: 1),
                'sg_doc_type'        => PropertySgDocument::normaliseDocType($docType),
                'sg_doc_type_raw'    => $docType,
                'sg_source_url'      => $this->absolutiseSgUrl($href),
            ];
        }

        return $documents;
    }

    private function absolutiseSgUrl(string $href): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }
        if (str_starts_with($href, './')) {
            return self::BASE_URL . substr($href, 1);
        }
        if (str_starts_with($href, '/')) {
            return rtrim(parse_url(self::BASE_URL, PHP_URL_SCHEME) . '://' . parse_url(self::BASE_URL, PHP_URL_HOST), '/') . $href;
        }
        return self::BASE_URL . '/' . $href;
    }

    private function cacheResult(string $hash, array $normalised, string $rawHtml, array $parsed): void
    {
        SgSearchCache::updateOrCreate(
            ['query_hash' => $hash],
            [
                'province'              => $normalised['province'],
                'rural_urban'           => $normalised['rural_urban'],
                'town'                  => $normalised['town'],
                'parcel_number'         => $normalised['parcel_number'],
                'portion'               => $normalised['portion'],
                'farm_name'             => $normalised['farm_name'],
                'response_body'         => mb_substr($rawHtml, 0, 100_000),
                'parsed_documents_json' => $parsed,
                'fetched_at'            => now(),
                'expires_at'            => now()->addHours(self::CACHE_HOURS),
            ],
        );
    }

    private function errorResult(string $msg, array $normalised): SgSearchResult
    {
        return new SgSearchResult(
            documents:     [],
            fromCache:     false,
            fetchedAt:     null,
            errorMessage:  $msg,
            resolvedQuery: $normalised,
        );
    }
}
