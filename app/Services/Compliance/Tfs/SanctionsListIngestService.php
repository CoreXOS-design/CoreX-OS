<?php

namespace App\Services\Compliance\Tfs;

use App\Models\Compliance\SanctionsListEntry;
use App\Models\Compliance\SanctionsListImport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Fetch a TFS source feed, parse it, and replace that feed's entries in one transaction.
 *
 * FAIL LOUD: a fetch that is not HTTP 200 XML (geo-block, error page, empty) records a
 * `failed` import and throws — it NEVER writes entries or lets a stale list look fresh.
 * Idempotent: identical content SHA since the last success => `unchanged`, no re-parse.
 * Multi-source: the feed key selects config; adding a feed needs no code change here
 * beyond a format parser (currently only 'fic_xml').
 */
class SanctionsListIngestService
{
    public function ingest(string $feed, ?string $file = null, bool $force = false): SanctionsListImport
    {
        $cfg = config("tfs.feeds.$feed");
        if (! $cfg) {
            throw new RuntimeException("Unknown TFS feed [$feed] — not in config/tfs.php.");
        }

        $import = new SanctionsListImport([
            'source_feed'  => $feed,
            'source_label' => $cfg['label'] ?? $feed,
            'source_url'   => $cfg['url'] ?? null,
            'fetch_method' => $file ? 'file' : ($cfg['method'] ?? 'http_post'),
            'status'       => 'failed',
        ]);
        $import->started_at = Carbon::now();

        // ── 1. Acquire the payload ────────────────────────────────────────────
        try {
            [$body, $httpStatus, $filename] = $file
                ? $this->readFile($file)
                : $this->fetch($cfg);
        } catch (\Throwable $e) {
            $import->error = 'fetch: ' . $e->getMessage();
            $import->finished_at = Carbon::now();
            $import->save();
            Log::channel('stack')->error("TFS ingest FETCH FAILED [$feed]: " . $e->getMessage());
            throw $e; // fail loud
        }

        $import->http_status     = $httpStatus;
        $import->source_filename = $filename;
        $import->file_bytes      = strlen($body);
        $import->content_sha256  = hash('sha256', $body);

        // ── 2. Validate it is actually XML, not an error/landing page ─────────
        if (! $this->looksLikeXml($body)) {
            $import->error = 'payload is not XML (possible geo-block / error page). First 200 bytes: '
                . substr(preg_replace('/\s+/', ' ', $body), 0, 200);
            $import->finished_at = Carbon::now();
            $import->save();
            Log::channel('stack')->error("TFS ingest NON-XML [$feed] http=$httpStatus");
            throw new RuntimeException($import->error);
        }

        // ── 3. Unchanged? (idempotent) ────────────────────────────────────────
        $last = SanctionsListImport::latestSuccessful($feed);
        if (! $force && $last && $last->content_sha256 === $import->content_sha256) {
            $import->status = 'unchanged';
            $import->record_count      = $last->record_count;
            $import->individual_count  = $last->individual_count;
            $import->entity_count      = $last->entity_count;
            $import->finished_at = Carbon::now();
            $import->save();
            return $import;
        }

        // ── 4. Parse ──────────────────────────────────────────────────────────
        $records = $this->parseFicXml($body);
        if (count($records) === 0) {
            $import->error = 'parsed 0 records — refusing to wipe the live list.';
            $import->finished_at = Carbon::now();
            $import->save();
            throw new RuntimeException($import->error);
        }

        // ── 5. Replace the feed's data in one transaction ─────────────────────
        DB::transaction(function () use ($import, $feed, $records) {
            $import->save(); // get id first

            // Full refresh for this feed (aliases/identifiers cascade on delete).
            SanctionsListEntry::where('source_feed', $feed)->delete();

            $indiv = 0;
            $entity = 0;
            foreach ($records as $r) {
                $entry = SanctionsListEntry::create([
                    'source_feed'     => $feed,
                    'import_id'       => $import->id,
                    'external_ref'    => $r['ref'],
                    'record_kind'     => $r['kind'],
                    'primary_name'    => TfsNormalizer::cap($r['name'], 500),
                    'normalised_name' => TfsNormalizer::cap(TfsNormalizer::name($r['name']), 500),
                    'date_of_birth'   => $r['dob'],
                    'dob_raw'         => $r['dob_raw'],
                    'place_of_birth'  => $r['pob'],
                    'nationality'     => $r['nationality'],
                    'designation'     => $r['designation'],
                    'address'         => $r['address'],
                    'comments'        => $r['comments'],
                    'listed_on'       => $r['listed_on'],
                    'raw'             => $r['raw'],
                ]);
                $r['kind'] === 'entity' ? $entity++ : $indiv++;

                // Each alias ELEMENT may pack many aliases with UN quality markers.
                $seenAlias = [];
                foreach ($r['aliases'] as $aliasBlob) {
                    foreach (TfsNormalizer::parseAliases($aliasBlob) as $alias) {
                        $norm = TfsNormalizer::name($alias);
                        if ($norm === '' || isset($seenAlias[$norm])) {
                            continue;
                        }
                        $seenAlias[$norm] = true;
                        $entry->aliases()->create([
                            'source_feed'      => $feed,
                            'alias'            => TfsNormalizer::cap($alias, 500),
                            'normalised_alias' => TfsNormalizer::cap($norm, 500),
                        ]);
                    }
                }
                foreach ($r['identifiers'] as $doc) {
                    $norm = TfsNormalizer::identifier($doc['value']);
                    if ($norm === '') {
                        continue;
                    }
                    $entry->identifiers()->create([
                        'source_feed'      => $feed,
                        'id_type'          => $doc['type'],
                        'id_value'         => TfsNormalizer::cap($doc['value'], 160),
                        'normalised_value' => TfsNormalizer::cap($norm, 160),
                    ]);
                }
            }

            $import->status           = 'success';
            $import->record_count     = count($records);
            $import->individual_count = $indiv;
            $import->entity_count     = $entity;
            $import->finished_at      = Carbon::now();
            $import->save();
        });

        return $import;
    }

    /** POST with an empty body (Content-Length: 0) — the FIC endpoint requires it. */
    private function fetch(array $cfg): array
    {
        $timeout = (int) config('tfs.fetch_timeout', 90);
        $resp = Http::timeout($timeout)
            ->withHeaders(['Content-Length' => '0'])
            ->withBody('', 'application/x-www-form-urlencoded')
            ->post($cfg['url']);

        $filename = null;
        if ($cd = $resp->header('Content-Disposition')) {
            if (preg_match('/filename="?([^"]+)"?/', $cd, $m)) {
                $filename = $m[1];
            }
        }
        if (! $resp->successful()) {
            throw new RuntimeException("HTTP {$resp->status()} from feed (expected 200).");
        }
        return [$resp->body(), $resp->status(), $filename];
    }

    private function readFile(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("File not readable: $path");
        }
        return [file_get_contents($path), null, basename($path)];
    }

    private function looksLikeXml(string $body): bool
    {
        $head = ltrim(substr($body, 0, 200));
        return str_starts_with($head, '<?xml') || str_contains($head, '<NewDataSet');
    }

    /**
     * Parse the FIC "NewDataSet" XML. Individuals and entities are distinguished by
     * their field set (Individual* vs Entity*), not by the wrapper tag.
     */
    private function parseFicXml(string $body): array
    {
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            throw new RuntimeException('XML did not parse.');
        }

        $records = [];
        foreach ($xml->children() as $row) {
            $map = [];
            $aliases = [];
            foreach ($row->children() as $child) {
                $tag = $child->getName();
                $val = trim((string) $child);
                if ($tag === 'IndividualAlias' || $tag === 'EntityAlias') {
                    $aliases[] = $val;
                } else {
                    // keep last non-empty for repeated scalar tags
                    if ($val !== '' || ! isset($map[$tag])) {
                        $map[$tag] = $val;
                    }
                }
            }

            $isEntity = isset($map['EntityID']) || isset($map['EntityAddress']) || isset($map['EntityAlias']);
            $name = $isEntity
                ? ($map['FirstName'] ?? $map['FullName'] ?? '')   // entity name lives in FirstName
                : ($map['FullName'] ?? $map['FirstName'] ?? '');
            $name = trim(preg_replace('/\s+/', ' ', $name));
            if ($name === '') {
                continue; // no name = nothing to screen against
            }

            $records[] = [
                'ref'         => $map['ReferenceNumber'] ?? null,
                'kind'        => $isEntity ? 'entity' : 'individual',
                'name'        => $name,
                'dob'         => TfsNormalizer::parseDate($map['IndividualDateOfBirth'] ?? null),
                'dob_raw'     => $map['IndividualDateOfBirth'] ?? null,
                'pob'         => $map['IndividualPlaceOfBirth'] ?? null,
                'nationality' => $map['Nationality'] ?? null,
                'designation' => $map['Designation'] ?? $map['Title'] ?? null,
                'address'     => $map['IndividualAddress'] ?? $map['EntityAddress'] ?? null,
                'comments'    => $map['Comments'] ?? null,
                'listed_on'   => TfsNormalizer::parseDate($map['ListedOn'] ?? null),
                'aliases'     => $aliases,
                'identifiers' => TfsNormalizer::parseDocuments($map['IndividualDocument'] ?? null),
                'raw'         => $map,
            ];
        }
        return $records;
    }
}
