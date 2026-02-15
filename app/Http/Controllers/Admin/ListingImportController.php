<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ListingImportRun;
use App\Models\ListingStock;
use App\Models\User;
use App\Services\Listings\ListingImportMapper;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ListingImportController extends Controller
{
    public function index()
    {
        $runs = ListingImportRun::query()
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        return view('admin.listings.import', [
            'runs' => $runs,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required','file','mimes:xlsx','max:25600'],
        ]);

        $u = Auth::user();
        abort_unless($u, 403);

        $file = $request->file('file');
        $original = $file->getClientOriginalName() ?: 'propcon.xlsx';

        $storedPath = $file->store('listings-imports');

        $run = ListingImportRun::create([
            'imported_by_user_id' => $u->id,
            'branch_id' => $u->branch_id ?? null,
            'source' => 'propcon',
            'original_filename' => $original,
            'status' => 'draft',
        ]);

        try {
            $fullPath = Storage::path($storedPath);

            $spreadsheet = IOFactory::load($fullPath);
            $sheet = $spreadsheet->getActiveSheet();
            $rawRows = $sheet->toArray(null, true, true, false);

            if (count($rawRows) < 2) {
                $run->update(['status' => 'failed', 'error_message' => 'Spreadsheet contains no data rows.']);
                return redirect()->route('admin.listings.import')->withErrors(['file' => 'Spreadsheet contains no data rows.']);
            }

            $headers = array_map(fn($x) => is_string($x) ? trim($x) : (string)$x, $rawRows[0] ?? []);
            $dataRows = array_slice($rawRows, 1);

            $mapping = ListingImportMapper::suggestMapping($headers);
            $validation = ListingImportMapper::validateRequired($mapping);

            $run->update([
                'header_row' => $headers,
                'column_mapping' => $mapping,
                'status' => $validation['ok'] ? 'ready' : 'failed',
                'error_message' => $validation['ok'] ? null : ('Missing required fields: ' . implode(', ', $validation['missing'])),
            ]);

            if (!$validation['ok']) {
                return redirect()
                    ->route('admin.listings.import')
                    ->withErrors(['file' => 'Missing required fields: ' . implode(', ', $validation['missing'])]);
            }

            $agentIdx = $mapping['agent'] ?? null;

            // Build lookup tables for users (email + name)
            $users = User::query()->select('id','name','email','branch_id')->get();

            $byEmail = [];
            $byName = [];
            foreach ($users as $usr) {
                $e = self::normEmail($usr->email ?? '');
                if ($e) $byEmail[$e] = $usr;

                $n = self::normName($usr->name ?? '');
                if ($n) $byName[$n] = $usr;
            }

            $created = 0;
            $updated = 0;
            $skipped = 0;

            DB::beginTransaction();


            $parseDateCell = function ($v): ?string {
                if ($v === null || $v === '') return null;
                try {
                    // PhpSpreadsheet may return Excel serial numbers for dates
                    if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
                        $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$v);
                        return $dt ? Carbon::instance($dt)->toDateTimeString() : null;
                    }
                    if (is_string($v)) {
                        $v = trim($v);
                        if ($v === '') return null;
                        return Carbon::parse($v)->toDateTimeString();
                    }
                } catch (\Throwable $e) {
                    return null;
                }
                return null;
            };

            foreach ($dataRows as $row) {
                $payload = [];
                foreach ($headers as $i => $h) {
                    $payload[$h !== '' ? $h : ('col_' . $i)] = $row[$i] ?? null;
                }

                $externalId  = array_key_exists('external_id', $mapping)  ? trim((string)($row[$mapping['external_id']] ?? ''))  : '';
                $externalRef = array_key_exists('external_ref', $mapping) ? trim((string)($row[$mapping['external_ref']] ?? '')) : '';
                $property    = array_key_exists('property', $mapping)     ? trim((string)($row[$mapping['property']] ?? ''))     : '';
                $status      = array_key_exists('status', $mapping)       ? trim((string)($row[$mapping['status']] ?? ''))       : '';
                $priceCents  = array_key_exists('price', $mapping)        ? ListingImportMapper::parsePriceToCents($row[$mapping['price']] ?? null) : null;

                $loadedIdx   = array_key_exists('loaded_at', $mapping)   ? $mapping['loaded_at']   : null;
                $modifiedIdx = array_key_exists('modified_at', $mapping) ? $mapping['modified_at'] : null;
                $listedIdx   = array_key_exists('listed_at', $mapping)   ? $mapping['listed_at']   : null;
                $expireIdx   = array_key_exists('expires_at', $mapping)  ? $mapping['expires_at']  : null;

                if ($externalId === '' && $externalRef === '' && $property === '') {
                    $skipped++;
                    continue;
                }

                // Determine user_id from Agents column (robust)
                $targetUser = null;
                if (is_int($agentIdx)) {
                    $agentCell = $row[$agentIdx] ?? null;
                    $targetUser = self::matchUserFromAgentCell($agentCell, $byEmail, $byName);
                }
                if (!$targetUser) $targetUser = $u;

                // Find existing listing (idempotent)
                $q = ListingStock::query()->where('source', 'propcon');
                if ($externalId !== '') {
                    $q->where('external_id', $externalId);
                } elseif ($externalRef !== '') {
                    $q->where('external_ref', $externalRef);
                } else {
                    $q->where('user_id', $targetUser->id)->where('property', $property);
                }

                $existing = $q->first();

                $data = [
                    'user_id' => $targetUser->id,
                    'branch_id' => $targetUser->branch_id ?? null,
                    'source' => 'propcon',
                    'external_id' => $externalId !== '' ? $externalId : null,
                    'external_ref' => $externalRef !== '' ? $externalRef : null,
                    'property' => $property !== '' ? $property : null,
                    'status' => $status !== '' ? $status : null,
                    'price_cents' => $priceCents,
                    'raw_payload' => $payload,
                ];

                  // Dates from import (Propcon)
                  $loadedAt   = is_int($loadedIdx)   ? $parseDateCell($row[$loadedIdx]   ?? null) : null;
                  $modifiedAt = is_int($modifiedIdx) ? $parseDateCell($row[$modifiedIdx] ?? null) : null;
                  $listedAt   = is_int($listedIdx)   ? $parseDateCell($row[$listedIdx]   ?? null) : null;
                  $expiresAt  = is_int($expireIdx)   ? $parseDateCell($row[$expireIdx]   ?? null) : null;

                  if ($listedAt) $data['listed_at'] = $listedAt;
                  elseif ($loadedAt) $data['listed_at'] = $loadedAt;

                  if ($modifiedAt) $data['modified_at'] = $modifiedAt;
                  elseif ($loadedAt) $data['modified_at'] = $loadedAt;

                  if ($expiresAt) $data['expires_at'] = $expiresAt;


                // Now that mapper synonyms exist, these will populate when mapped
                if (array_key_exists('category', $mapping)) $data['category'] = trim((string)($row[$mapping['category']] ?? '')) ?: null;
                if (array_key_exists('type', $mapping))     $data['type']     = trim((string)($row[$mapping['type']] ?? '')) ?: null;
                if (array_key_exists('region', $mapping))   $data['region']   = trim((string)($row[$mapping['region']] ?? '')) ?: null;
                if (array_key_exists('mandate', $mapping))  $data['mandate']  = trim((string)($row[$mapping['mandate']] ?? '')) ?: null;

                if ($existing) {
                    $existing->fill($data)->save();
                    $updated++;
                } else {
                    ListingStock::create($data);
                    $created++;
                }
            }

            DB::commit();

            $run->update([
                'status' => 'applied',
                'error_message' => null,
            ]);

            return redirect()
                ->route('admin.listings.agents')
                ->with('status', "Import applied. Created: {$created}, Updated: {$updated}, Skipped: {$skipped}.");
        } catch (\Throwable $e) {
            DB::rollBack();
            $run->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            return redirect()->route('admin.listings.import')->withErrors(['file' => 'Import failed: ' . $e->getMessage()]);
        }
    }

    private static function matchUserFromAgentCell(mixed $cell, array $byEmail, array $byName): ?User
    {
        // Normalize to string
        $s = '';
        if (is_string($cell)) $s = trim($cell);
        elseif (is_numeric($cell)) $s = (string)$cell;

        if ($s === '') return null;

        // 1) Email match if any email exists inside the cell
        if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $s, $m)) {
            foreach ($m[0] as $email) {
                $k = self::normEmail($email);
                if ($k && isset($byEmail[$k])) return $byEmail[$k];
            }
        }

        // 2) Split on common separators and try name matching
        $parts = preg_split('/[;,\/\|\&]+|\band\b/i', $s) ?: [$s];
        foreach ($parts as $part) {
            $cand = trim((string)$part);
            if ($cand === '') continue;

            // strip bracketed hints like "(Primary)" etc
            $cand = preg_replace('/\([^)]*\)/', '', $cand) ?? $cand;
            $cand = trim($cand);

            // exact normalized match
            $nk = self::normName($cand);
            if ($nk && isset($byName[$nk])) return $byName[$nk];

            // loose contains match against known user names
            foreach ($byName as $nameKey => $usr) {
                if ($nameKey === '') continue;
                if (str_contains($nk, $nameKey) || str_contains($nameKey, $nk)) {
                    return $usr;
                }
            }
        }

        return null;
    }

    private static function normName(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return $s;
    }

    private static function normEmail(string $s): string
    {
        $s = strtolower(trim($s));
        return $s ?: '';
    }
}
