<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\FicaSubmission;
use App\Models\ContactNote;
use App\Models\ContactSource;
use App\Models\ContactTag;
use App\Models\ContactType;
use App\Models\Scopes\ContactScope;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class ContactImportController extends Controller
{
    /**
     * Excel column header → internal field mapping.
     * Keys are normalised (lowercase, trimmed) header names from the spreadsheet.
     */
    private const HEADER_MAP = [
        // Name fields
        'name'        => 'first_name',
        'first name'  => 'first_name',
        'firstname'   => 'first_name',
        'surname'     => 'last_name',
        'last name'   => 'last_name',
        'lastname'    => 'last_name',

        // Contact info
        'email'       => 'email',
        'e-mail'      => 'email',
        'cell'        => 'phone',
        'cell phone'  => 'phone',
        'cellphone'   => 'phone',
        'mobile'      => 'phone',
        'phone'       => 'phone_secondary',
        'telephone'   => 'phone_secondary',
        'tel'         => 'phone_secondary',

        // Identity
        '*id number'  => 'id_number',
        'id number'   => 'id_number',
        'id no'       => 'id_number',
        'id_number'   => 'id_number',
        'birthday'    => 'birthday',
        'birthdate'   => 'birthday',
        'date of birth' => 'birthday',
        'dob'         => 'birthday',

        // Address
        'address'     => 'address',

        // Classification
        'category'    => '_category',
        'type'        => '_type',
        'source'      => '_source',
        'tags'        => '_tags',

        // Agent
        'agents'      => '_agent',
        'agent'       => '_agent',

        // WhatsApp
        'whatsapp'    => '_whatsapp',

        // Organisation
        'org./in'     => '_organisation',
        'org/in'      => '_organisation',
        'organisation' => '_organisation',
        'organization' => '_organisation',
        'company'     => '_organisation',

        // Web / Work
        'web'         => '_web',
        'website'     => '_web',
        'work'        => '_work',

        // Dates
        'loaded'      => '_loaded_at',
        'modified'    => '_modified_at',
        'last contacted' => '_last_contacted',

        // Notes
        'notes'                    => 'notes',
        'note'                     => 'notes',
        'additional info'          => 'notes',
        'additionalinfo'           => 'notes',
        'additional_info'          => 'notes',
        'additional information'   => 'notes',
        'additionalinformation'    => 'notes',
        'comments'                 => 'notes',
        'comment'                  => 'notes',
        'remarks'                  => 'notes',
        'remark'                   => 'notes',
        'description'              => 'notes',
        'info'                     => 'notes',
        'message'                  => 'notes',
        'details'                  => 'notes',

        // Counts
        'emails'      => '_emails_count',
        'members'     => '_members',
        'sub'         => '_sub',
    ];

    public function import(Request $request)
    {
        set_time_limit(300); // Allow up to 5 minutes for large imports

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:25600'],
        ]);

        $markFicaApproved = $request->boolean('mark_fica_approved');

        // Auto-stamping APPROVED FICA submissions is a compliance action — it must
        // not be reachable by anyone who merely holds access_contacts. Require the
        // dedicated FICA-approve permission; reject (not silently drop) if missing.
        if ($markFicaApproved) {
            abort_unless(
                $request->user()?->hasPermission('compliance.fica.approve'),
                403,
                'You do not have permission to mark imported contacts as FICA-approved.'
            );
        }

        $file     = $request->file('file');
        $fullPath = $file->getRealPath();
        $ext      = strtolower($file->getClientOriginalExtension() ?: 'xlsx');

        // Stream the rows. OpenSpout reads one row at a time at constant memory,
        // so a 50k-contact import no longer exhausts the PHP memory_limit the way
        // PhpSpreadsheet's load-everything-into-objects approach did.
        $rows = $this->streamRows($fullPath, $ext);

        try {
            // First row = headers. valid() lazily opens the file / reads row 1.
            if (!$rows->valid()) {
                return back()->with('error', 'Spreadsheet contains no data rows.');
            }
            $headers = array_map(fn($x) => is_string($x) ? trim($x) : (string) ($x ?? ''), $rows->current());
            $rows->next();
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not read spreadsheet: ' . $e->getMessage());
        }

        // Build column index → field mapping
        $mapping = $this->buildMapping($headers);

        // Build agent lookup tables
        $users   = User::select('id', 'name', 'email', 'branch_id')->get();
        $byEmail = [];
        $byName  = [];
        foreach ($users as $usr) {
            $e = strtolower(trim($usr->email ?? ''));
            if ($e) $byEmail[$e] = $usr;
            $n = strtolower(trim($usr->name ?? ''));
            if ($n) $byName[$n] = $usr;
        }

        // Cache type/source/tag lookups
        $typeCache   = ContactType::pluck('id', 'name')->mapWithKeys(fn($id, $n) => [strtolower($n) => $id])->toArray();
        $sourceCache = ContactSource::pluck('id', 'name')->mapWithKeys(fn($id, $n) => [strtolower($n) => $id])->toArray();
        $tagCache    = ContactTag::pluck('id', 'name')->mapWithKeys(fn($id, $n) => [strtolower($n) => $id])->toArray();

        // Resolve a fallback branch for the import. contacts.branch_id is NOT NULL,
        // and the BelongsToBranch trait only auto-fills it from the importing user's
        // own branch_id — which is empty for agency-level / owner accounts. Resolve
        // an explicit chain so no contact is ever inserted with a NULL branch_id:
        //   agency's default branch → agency's lowest active branch.
        // (Per-row, the resolved agent's branch takes precedence — see below.)
        $importingUser = auth()->user();
        $importAgencyId = $importingUser && method_exists($importingUser, 'effectiveAgencyId')
            ? $importingUser->effectiveAgencyId()
            : ($importingUser->agency_id ?? null);

        $fallbackBranchId = $importingUser->branch_id ?? null;
        if (!$fallbackBranchId && $importAgencyId) {
            $fallbackBranchId = DB::table('agencies')->where('id', $importAgencyId)->value('default_branch_id');
        }
        if (!$fallbackBranchId && $importAgencyId) {
            $fallbackBranchId = DB::table('branches')
                ->where('agency_id', $importAgencyId)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->value('id');
        }

        if (!$fallbackBranchId) {
            return back()->with('error', 'Import aborted: no branch could be resolved for this agency. Create a branch (or set the agency default branch) before importing contacts.');
        }

        $created  = 0;
        $skipped  = 0;
        $errors   = [];
        $rowNum   = 1; // header occupied row 1

        // Collect (contact, tagIds) for post-commit domain event emission.
        $pendingTagEvents = [];
        $tagNameById = ContactTag::pluck('name', 'id')->all();

        DB::beginTransaction();

        try {
            // for-loop (not foreach) so the existing `continue` skips still advance
            // the row generator via the loop's increment expression.
            for (; $rows->valid(); $rows->next()) {
                $rowNum++;
                $row     = $rows->current();
                $mapped  = $this->mapRow($row, $mapping);

                $firstName = trim($mapped['first_name'] ?? '');
                $lastName  = trim($mapped['last_name'] ?? '');

                // Skip empty rows
                if ($firstName === '' && $lastName === '') {
                    $skipped++;
                    continue;
                }

                $phone = trim($mapped['phone'] ?? '');
                $email = trim($mapped['email'] ?? '');

                // If no phone but phone_secondary exists, use that
                if ($phone === '' && !empty($mapped['phone_secondary'])) {
                    $phone = trim($mapped['phone_secondary']);
                }

                // Phone is required in the DB — skip rows with no phone at all
                if ($phone === '') {
                    $skipped++;
                    continue;
                }

                // Duplicate check by phone or email. Bypass ContactScope (the
                // role-based 'own'/'branch' read filter) so dedupe sees EVERY
                // contact in the agency — otherwise an 'own'-scope importer can't
                // see colleagues' contacts and creates agency-wide duplicates.
                // AgencyScope still bounds this to the current agency.
                $dup = Contact::withoutGlobalScope(ContactScope::class)
                    ->where(function ($q) use ($phone, $email) {
                        $q->where('phone', $phone);
                        if ($email !== '') $q->orWhere('email', $email);
                    })
                    ->exists();

                if ($dup) {
                    $skipped++;
                    continue;
                }

                // Resolve agent
                $agentUser = $this->resolveAgent($mapped['_agent'] ?? '', $byEmail, $byName);
                $agentId   = $agentUser ? $agentUser->id : auth()->id();

                // Attach the contact to its agent's branch when known, else the
                // resolved agency fallback. branch_id is NOT NULL on contacts.
                $branchId = ($agentUser && $agentUser->branch_id) ? $agentUser->branch_id : $fallbackBranchId;

                // Resolve contact type
                $typeId = $this->resolveType($mapped['_type'] ?? '', $typeCache);

                // Resolve source (auto-create if new)
                $sourceId = $this->resolveSource($mapped['_source'] ?? '', $sourceCache);

                // Resolve tags (auto-create if new)
                $tagIds = $this->resolveTags($mapped['_tags'] ?? '', $tagCache);

                // Build notes from additional info
                $notes = $this->buildNotes($mapped);

                // Parse dates
                $birthday        = $this->parseDate($mapped['birthday'] ?? null);
                $loadedAt        = $this->parseDateTime($mapped['_loaded_at'] ?? null);
                $modifiedAt      = $this->parseDateTime($mapped['_modified_at'] ?? null);
                $lastContactedAt = $this->parseDateTime($mapped['_last_contacted'] ?? null);

                $contact = Contact::create([
                    'branch_id'          => $branchId,
                    'first_name'         => $firstName,
                    'last_name'          => $lastName,
                    'phone'              => $phone ?: null,
                    'email'              => $email ?: null,
                    'id_number'          => trim($mapped['id_number'] ?? '') ?: null,
                    'birthday'           => $birthday,
                    'address'            => trim($mapped['address'] ?? '') ?: null,
                    'contact_type_id'    => $typeId,
                    'contact_source_id'  => $sourceId,
                    'created_by_user_id' => $agentId,
                    'loaded_at'          => $loadedAt,
                    'modified_at'        => $modifiedAt,
                    'last_contacted_at'  => $lastContactedAt,
                    'email_count'        => max(0, (int) ($mapped['_emails_count'] ?? 0)),
                    'whatsapp_count'     => max(0, (int) ($mapped['_whatsapp'] ?? 0)),
                ]);

                // Create a note in the Notes tab if there's additional info
                if ($notes !== '') {
                    ContactNote::create([
                        'contact_id' => $contact->id,
                        'user_id'    => $agentId,
                        'body'       => $notes,
                    ]);
                }

                // Attach tags
                if (!empty($tagIds)) {
                    $contact->tags()->attach($tagIds);
                    $pendingTagEvents[] = [$contact, $tagIds];
                }

                // Go-live migration: stamp an approved FICA submission so the
                // contact appears FICA-compliant immediately. Pre-existing
                // contacts brought over from a prior CRM are treated as
                // already-verified for go-live.
                if ($markFicaApproved && $contact->agency_id) {
                    FicaSubmission::create([
                        'contact_id'          => $contact->id,
                        'agency_id'           => $contact->agency_id,
                        'requested_by'        => $agentId,
                        'token'               => bin2hex(random_bytes(32)),
                        'token_expires_at'    => now()->addYear(),
                        'entity_type'         => 'natural',
                        'status'              => 'approved',
                        'verification_method' => ['source' => 'go_live_migration'],
                        'verified_by'         => auth()->id() ?: $agentId,
                        'verified_at'         => now(),
                        'reviewer_notes'      => 'Auto-approved via contact Excel import (agency go-live migration).',
                    ]);
                }

                $created++;
            }

            DB::commit();

            // Fire ContactTagged events AFTER commit (spec corex-domain-events-spec.md).
            foreach ($pendingTagEvents as [$contact, $tagIds]) {
                foreach ($tagIds as $tagId) {
                    event(new \App\Events\Contact\ContactTagged(
                        contact: $contact,
                        tag: (string) ($tagNameById[$tagId] ?? $tagId),
                        actorUserId: auth()->id(),
                    ));
                }
            }

            return redirect()->route('corex.contacts.index')
                ->with('success', "Import complete. Created: {$created}, Skipped (duplicates/empty): {$skipped}.");

        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Import failed: ' . $e->getMessage());
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Yield spreadsheet rows one at a time as 0-indexed arrays of cell values.
     *
     * .xlsx / .csv stream through OpenSpout at constant memory. Legacy binary
     * .xls (which OpenSpout cannot read) falls back to PhpSpreadsheet — those
     * files are capped at 65k rows so the in-memory load is acceptable.
     */
    private function streamRows(string $path, string $ext): \Generator
    {
        if ($ext === 'xls') {
            yield from $this->streamRowsLegacyXls($path);
            return;
        }

        $reader = $ext === 'csv' ? new CsvReader() : new XlsxReader();
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    yield $row->toArray();
                }
                break; // first sheet only — mirrors the previous getActiveSheet()
            }
        } finally {
            $reader->close();
        }
    }

    /** PhpSpreadsheet fallback for legacy .xls (small, legacy files only). */
    private function streamRowsLegacyXls(string $path): \Generator
    {
        $spreadsheet = IOFactory::load($path);
        $rawRows     = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        foreach ($rawRows as $row) {
            yield $row;
        }
    }

    private function buildMapping(array $headers): array
    {
        $mapping = [];
        foreach ($headers as $colIdx => $header) {
            // Aggressive normalisation: lowercase, collapse whitespace, strip non-breaking spaces
            $normalised = strtolower(trim($header));
            $normalised = preg_replace('/[\xC2\xA0\x{00A0}]+/u', ' ', $normalised); // non-breaking spaces
            $normalised = preg_replace('/\s+/', ' ', $normalised); // collapse whitespace
            $normalised = trim($normalised);

            if (isset(self::HEADER_MAP[$normalised])) {
                $mapping[$colIdx] = self::HEADER_MAP[$normalised];
                continue;
            }

            // Try stripping leading special chars (e.g. "*ID Number")
            $cleaned = ltrim($normalised, '*');
            if (isset(self::HEADER_MAP[$cleaned])) {
                $mapping[$colIdx] = self::HEADER_MAP[$cleaned];
                continue;
            }

            // Try with all non-alphanumeric stripped (catches "Additional-Info", "Additional_Info", etc.)
            $stripped = preg_replace('/[^a-z0-9]/', '', $normalised);
            foreach (self::HEADER_MAP as $key => $field) {
                if (preg_replace('/[^a-z0-9]/', '', $key) === $stripped && $stripped !== '') {
                    $mapping[$colIdx] = $field;
                    break;
                }
            }
            if (isset($mapping[$colIdx])) continue;

            // Fuzzy fallback: if header contains key note/info/comment words, map to notes
            if ($normalised !== '' && preg_match('/(note|info|comment|remark|additional|message)/i', $normalised)) {
                $mapping[$colIdx] = 'notes';
            }
        }
        return $mapping;
    }

    private function mapRow(array $row, array $mapping): array
    {
        $result = [];
        foreach ($mapping as $colIdx => $field) {
            $val = $row[$colIdx] ?? null;
            if (is_string($val)) {
                $val = trim($val);
                $val = $this->stripFormulaGuard($val);
            }
            // If field already exists (e.g. phone from 'cell'), don't overwrite with empty
            if (isset($result[$field]) && ($val === null || $val === '')) continue;
            $result[$field] = $val;
        }
        return $result;
    }

    /**
     * Reverse the spreadsheet formula-injection guard applied on export.
     *
     * ContactExportController prefixes a single quote to values that begin with
     * =, +, -, @, or a leading tab/CR so spreadsheets render them as text. On
     * re-import we strip that leading quote ONLY when it precedes a formula
     * trigger char, so a round-tripped phone like '+27… lands back as +27…
     * without mangling legitimately apostrophe-prefixed values.
     */
    private function stripFormulaGuard(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === "'" && in_array($value[1], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return substr($value, 1);
        }

        return $value;
    }

    private function resolveAgent(mixed $agentCell, array $byEmail, array $byName): ?User
    {
        $s = is_string($agentCell) ? trim($agentCell) : '';
        if ($s === '') return null;

        // 1) Email match — highest priority
        if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $s, $m)) {
            foreach ($m[0] as $email) {
                $k = strtolower(trim($email));
                if (isset($byEmail[$k])) return $byEmail[$k];
            }
        }

        // 2) Name matching with scoring
        $parts = preg_split('/[;,\/\|\&]+|\band\b/i', $s) ?: [$s];
        foreach ($parts as $part) {
            $cand = strtolower(trim(preg_replace('/\([^)]*\)/', '', $part) ?? $part));
            if ($cand === '') continue;

            // Exact match
            if (isset($byName[$cand])) return $byName[$cand];

            // Score-based fuzzy match
            $candWords = preg_split('/\s+/', $cand);
            $bestScore = 0;
            $bestUser  = null;

            foreach ($byName as $nameKey => $usr) {
                if ($nameKey === '') continue;
                $dbWords = preg_split('/\s+/', $nameKey);
                $score   = 0;

                // Count how many words from the candidate match words in the DB name
                foreach ($candWords as $cw) {
                    foreach ($dbWords as $dw) {
                        // Exact word match
                        if ($cw === $dw) {
                            $score += 10;
                            break;
                        }
                        // Fuzzy word match (handles Reha→Retha, Reicel→Reichel)
                        $similarity = 0;
                        similar_text($cw, $dw, $similarity);
                        if ($similarity >= 75 && strlen($cw) >= 3) {
                            $score += 7;
                            break;
                        }
                        // Soundex match (phonetic)
                        if (strlen($cw) >= 3 && soundex($cw) === soundex($dw)) {
                            $score += 5;
                            break;
                        }
                    }
                }

                // Penalise DB names that have extra words (prefer "Elize Reicel" over "Elize Reichel Ballito")
                // Heavy penalty so exact word-count matches win over longer names
                $extraWords = max(0, count($dbWords) - count($candWords));
                $score -= $extraWords * 6;

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestUser  = $usr;
                }
            }

            // Require a minimum score to accept (at least one good word match)
            if ($bestUser && $bestScore >= 7) {
                return $bestUser;
            }

            // Last resort: first-name-only match if unique
            if (count($candWords) >= 1) {
                $firstName = $candWords[0];
                $firstNameMatches = [];
                foreach ($byName as $nameKey => $usr) {
                    $dbFirst = explode(' ', $nameKey)[0] ?? '';
                    $sim = 0;
                    similar_text($firstName, $dbFirst, $sim);
                    if ($firstName === $dbFirst || ($sim >= 80 && strlen($firstName) >= 3)) {
                        $firstNameMatches[] = $usr;
                    }
                }
                if (count($firstNameMatches) === 1) {
                    return $firstNameMatches[0];
                }
            }
        }

        return null;
    }

    private function resolveType(mixed $typeCell, array &$cache): ?int
    {
        $s = is_string($typeCell) ? trim($typeCell) : '';
        if ($s === '') return null;

        $key = strtolower($s);
        if (array_key_exists($key, $cache)) return $cache[$key];

        // AT-79: contact types are LOCKED to the 4 canonical parents — import may
        // NOT create new types. Map the spreadsheet value to a parent by role
        // name; unrecognised values resolve to null (no type, brought in via the
        // tags column instead) rather than a rogue 5th parent.
        $roleMap = [
            'seller' => 'seller', 'vendor' => 'seller', 'owner' => 'seller',
            'buyer'  => 'buyer',  'purchaser' => 'buyer',
            'lessor' => 'lessor', 'landlord' => 'lessor',
            'lessee' => 'lessee', 'tenant' => 'lessee',
        ];
        $role = $roleMap[$key] ?? null;
        if (!$role) {
            foreach ($roleMap as $fragment => $r) {
                if (str_contains($key, $fragment)) { $role = $r; break; }
            }
        }

        $id = $role ? ContactType::where('esign_role', $role)->value('id') : null;
        $cache[$key] = $id;
        return $id;
    }

    private function resolveSource(mixed $sourceCell, array &$cache): ?int
    {
        $s = is_string($sourceCell) ? trim($sourceCell) : '';
        if ($s === '') return null;

        $key = strtolower($s);
        if (isset($cache[$key])) return $cache[$key];

        // Auto-create the source
        $source = ContactSource::create([
            'name'       => $s,
            'color'      => '#6366f1',
            'sort_order' => 0,
        ]);
        $cache[$key] = $source->id;
        return $source->id;
    }

    private function resolveTags(mixed $tagsCell, array &$cache): array
    {
        $s = is_string($tagsCell) ? trim($tagsCell) : '';
        if ($s === '') return [];

        $ids = [];
        // Tags may be comma-separated or semicolon-separated
        $parts = preg_split('/[;,]+/', $s);
        foreach ($parts as $part) {
            $tagName = trim($part);
            if ($tagName === '') continue;

            $key = strtolower($tagName);
            if (isset($cache[$key])) {
                $ids[] = $cache[$key];
            } else {
                // Auto-create the tag
                $tag = ContactTag::create([
                    'name'       => $tagName,
                    'color'      => '#6366f1',
                    'sort_order' => 0,
                ]);
                $cache[$key] = $tag->id;
                $ids[] = $tag->id;
            }
        }

        return array_unique($ids);
    }

    private function buildNotes(array $mapped): string
    {
        $parts = [];

        if (!empty($mapped['notes'])) {
            $parts[] = $mapped['notes'];
        }

        // Category (Person, Company, etc.)
        if (!empty($mapped['_category'])) {
            $parts[] = "Category: {$mapped['_category']}";
        }

        // Organisation
        if (!empty($mapped['_organisation'])) {
            $parts[] = "Organisation: {$mapped['_organisation']}";
        }

        // Web
        if (!empty($mapped['_web'])) {
            $parts[] = "Website: {$mapped['_web']}";
        }

        // Work
        if (!empty($mapped['_work'])) {
            $parts[] = "Work: {$mapped['_work']}";
        }

        // Secondary phone
        if (!empty($mapped['phone_secondary'])) {
            $phone = trim($mapped['phone'] ?? '');
            $sec   = trim($mapped['phone_secondary']);
            // Only add if we already used cell as primary phone
            if ($phone !== '' && $sec !== '' && $sec !== $phone) {
                $parts[] = "Alt phone: {$sec}";
            }
        }

        // Members / Sub
        if (!empty($mapped['_members']) && $mapped['_members'] != '0') {
            $parts[] = "Members: {$mapped['_members']}";
        }
        if (!empty($mapped['_sub']) && $mapped['_sub'] != '0') {
            $parts[] = "Subscriptions: {$mapped['_sub']}";
        }

        return implode("\n", $parts);
    }

    private function parseDate(mixed $val): ?string
    {
        if ($val === null || $val === '') return null;

        // OpenSpout returns date-typed cells as DateTime objects.
        if ($val instanceof \DateTimeInterface) {
            return Carbon::parse($val->format('Y-m-d H:i:s'))->toDateString();
        }

        try {
            if (is_int($val) || is_float($val) || (is_string($val) && is_numeric($val))) {
                $dt = ExcelDate::excelToDateTimeObject((float) $val);
                return $dt ? Carbon::instance($dt)->toDateString() : null;
            }
            if (is_string($val)) {
                $val = trim($val);
                if ($val === '') return null;
                return Carbon::parse($val)->toDateString();
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    private function parseDateTime(mixed $val): ?string
    {
        if ($val === null || $val === '') return null;

        // OpenSpout returns date-typed cells as DateTime objects.
        if ($val instanceof \DateTimeInterface) {
            return Carbon::parse($val->format('Y-m-d H:i:s'))->toDateTimeString();
        }

        try {
            if (is_int($val) || is_float($val) || (is_string($val) && is_numeric($val))) {
                $dt = ExcelDate::excelToDateTimeObject((float) $val);
                return $dt ? Carbon::instance($dt)->toDateTimeString() : null;
            }
            if (is_string($val)) {
                $val = trim($val);
                if ($val === '') return null;
                return Carbon::parse($val)->toDateTimeString();
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }
}
