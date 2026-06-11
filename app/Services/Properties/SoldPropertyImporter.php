<?php

namespace App\Services\Properties;

use App\Models\Property;
use App\Models\PropertyMarketingActivity;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Imports a spreadsheet of SOLD listings (the legacy portal export) into the
 * Property pillar (Agency Stock). One row → one Property, marked Sold, with the
 * embedded Primary Photo as the listing image and the named agent(s) assigned.
 *
 * Flow is two-step:
 *   1. preview()  — parse + auto-match agents, returns rows for the review screen.
 *   2. import()   — given a confirmed agent per row, create the properties.
 *
 * Columns are resolved by header name (case/space-insensitive) so a future
 * re-ordered export still imports correctly. See
 * .ai/specs/sold-properties-import.md.
 */
class SoldPropertyImporter
{
    /** @var array<string,User> name (normalised) → user */
    private array $usersByName = [];

    /** @var array<string,User> email (normalised) → user */
    private array $usersByEmail = [];

    /** @var array<int,string> user id → name */
    private array $usersById = [];

    /**
     * Parse the file and return one preview row per data row, with the
     * auto-matched agent (if any) for the review screen.
     *
     * @return array<int,array{row:int,title:string,suburb:?string,city:?string,price:?int,agents_text:string,matched_agent_id:?int,matched_agent_name:?string,second_agent_id:?int,has_image:bool}>
     */
    public function preview(string $absolutePath, ?int $agencyId): array
    {
        $this->buildUserLookups($agencyId);

        $sheet      = IOFactory::load($absolutePath)->getActiveSheet();
        $headerMap  = $this->buildHeaderMap($sheet);
        $imageByRow = $this->buildImageMap($sheet);
        $highestRow = $sheet->getHighestDataRow();

        $rows = [];
        for ($row = 2; $row <= $highestRow; $row++) {
            $parsed = $this->parseRowData($sheet, $headerMap, $row);
            if ($parsed === null) {
                continue;
            }
            $rows[] = [
                'row'                => $row,
                'title'              => $parsed['data']['title'],
                'suburb'             => $parsed['data']['suburb'],
                'city'               => $parsed['data']['city'],
                'price'              => $parsed['data']['price'],
                'agents_text'        => $parsed['detected']['text'],
                'matched_agent_id'   => $parsed['detected']['primary'],
                'matched_agent_name' => $parsed['detected']['primary_name'],
                'second_agent_id'    => $parsed['detected']['second'],
                'has_image'          => !empty($imageByRow[$row]),
            ];
        }
        return $rows;
    }

    /**
     * Create the properties. $agentByRow maps a sheet row number to the agent
     * id confirmed on the review screen; it overrides the auto-match.
     *
     * @param  array<int,int>  $agentByRow
     * @return array{created:int, rows:int, properties:array<int,int>, issues:array<int,string>}
     */
    public function import(string $absolutePath, User $actor, array $agentByRow = []): array
    {
        $agencyId = $actor->effectiveAgencyId();
        $branchId = $actor->effectiveBranchId();

        $this->buildUserLookups($agencyId);

        $sheet      = IOFactory::load($absolutePath)->getActiveSheet();
        $headerMap  = $this->buildHeaderMap($sheet);
        $imageByRow = $this->buildImageMap($sheet);
        $highestRow = $sheet->getHighestDataRow();

        $created    = 0;
        $rowCount   = 0;
        $createdIds = [];
        $issues     = [];

        for ($row = 2; $row <= $highestRow; $row++) {
            $parsed = $this->parseRowData($sheet, $headerMap, $row);
            if ($parsed === null) {
                continue;
            }
            $rowCount++;

            $agentId = $agentByRow[$row] ?? $parsed['detected']['primary'];
            if (!$agentId) {
                $issues[] = "Row {$row}: no agent assigned — skipped.";
                continue;
            }

            $secondAgentId = $parsed['detected']['second'];
            if ($secondAgentId === $agentId) {
                $secondAgentId = null;
            }

            try {
                $data = $parsed['data'] + [
                    'agent_id'           => $agentId,
                    'pp_second_agent_id' => $secondAgentId,
                    'agency_id'          => $agencyId,
                    'branch_id'          => $branchId ?: $this->agentBranchId($agentId),
                    'is_demo'            => false,
                ];

                $property = Property::create($data);

                if (!empty($imageByRow[$row])) {
                    $url = $this->storeImage($imageByRow[$row], $property->id);
                    if ($url !== null) {
                        $property->images_json = [$url];
                        $property->saveQuietly();
                    }
                }

                DB::table('property_sold_records')->insert([
                    'property_id'           => $property->id,
                    'address'               => $property->title,
                    'suburb'                => $property->suburb,
                    'sold_price'            => $parsed['data']['price'] ?? 0,
                    'sold_date'             => $parsed['sold_date'],
                    'listing_price_at_sale' => $parsed['data']['price'],
                    'property_type'         => $property->property_type,
                    'source'                => 'manual',
                    'source_reference'      => 'sold-import',
                    'captured_by_user_id'   => $actor->id,
                    'captured_at'           => now(),
                    'agency_id'             => $property->agency_id,
                    'created_at'            => now(),
                    'updated_at'            => now(),
                ]);

                PropertyMarketingActivity::create([
                    'property_id'       => $property->id,
                    'activity_type'     => 'other',
                    'activity_data'     => ['action' => 'marked_sold', 'source' => 'sold-import', 'sold_price' => $parsed['data']['price']],
                    'occurred_at'       => now(),
                    'logged_by_user_id' => $actor->id,
                ]);

                $created++;
                $createdIds[] = $property->id;
            } catch (\Throwable $e) {
                $issues[] = "Row {$row}: " . $e->getMessage();
            }
        }

        return [
            'created'    => $created,
            'rows'       => $rowCount,
            'properties' => $createdIds,
            'issues'     => $issues,
        ];
    }

    // ── Row parsing (shared by preview + import) ────────────────────────────

    /**
     * Parse one data row into property fields (minus agent) plus detected
     * agent info. Returns null for a blank trailing row.
     *
     * @return array{data:array<string,mixed>, detected:array{primary:?int,primary_name:?string,second:?int,text:string}, sold_date:string}|null
     */
    private function parseRowData(Worksheet $sheet, array $headerMap, int $row): ?array
    {
        $get = fn (string $key) => $this->cell($sheet, $headerMap, $key, $row);

        $address = trim((string) $get('address'));
        $price   = $this->parseInt($get('price'));

        if ($address === '' && $price === null) {
            return null; // blank trailing row
        }

        $parsedAddr = $this->parseAddress($address, (string) $get('region'));
        $agents     = $this->resolveAgents((string) $get('agents'));

        $soldDate = $this->parseDate($get('modified'))
            ?? $this->parseDate($get('listed'))
            ?? now()->toDateString();

        $data = [
            'title'              => $parsedAddr['title'],
            'address'            => $parsedAddr['street'] ?: ($address !== '' ? $address : null),
            'street_number'      => $parsedAddr['street_number'],
            'street_name'        => $parsedAddr['street_name'],
            'suburb'             => $parsedAddr['suburb'],
            'city'               => $parsedAddr['city'],
            'province'           => $parsedAddr['province'],
            'region'             => trim((string) $get('region')) ?: null,
            'price'              => $price,
            'category'           => trim((string) $get('category')) ?: null,
            'property_type'      => trim((string) $get('type')) ?: null,
            'listing_type'       => $this->mapListingType((string) $get('status_type')),
            'mandate_type'       => trim((string) $get('mandate')) ?: null,
            'status'             => 'sold',
            // beds/baths/garages are NOT NULL on the properties table — default to 0.
            'beds'               => $this->parseInt($get('bed')) ?? 0,
            'baths'              => $this->parseInt($get('bath')) ?? 0,
            'garages'            => $this->parseInt($get('garage')) ?? 0,
            'size_m2'            => $this->parseFloat($get('floor_size')),
            'erf_size_m2'        => $this->parseFloat($get('erf_size')),
            'rates_taxes'        => $this->parseFloat($get('rates')),
            'levy'               => $this->parseFloat($get('levy')),
            'features_json'      => $this->parseFeatures($get('keywords')),
            'description'        => trim((string) $get('tags')) ?: null,
            'external_id'        => trim((string) $get('reference_code')) ?: null,
            'p24_listing_number' => trim((string) $get('code')) ?: null,
            'listed_date'        => $this->parseDate($get('listed')),
            'expiry_date'        => $this->parseDate($get('expire')),
        ];

        return [
            'data'      => $data,
            'detected'  => $agents,
            'sold_date' => $soldDate,
        ];
    }

    // ── User matching ───────────────────────────────────────────────────────

    private function buildUserLookups(?int $agencyId): void
    {
        $this->usersByName = $this->usersByEmail = $this->usersById = [];

        $query = User::query()->select('id', 'name', 'email', 'agency_id', 'branch_id');
        if ($agencyId) {
            $query->where('agency_id', $agencyId);
        }
        foreach ($query->get() as $user) {
            $this->usersById[$user->id] = (string) $user->name;
            $e = strtolower(trim((string) $user->email));
            if ($e !== '') {
                $this->usersByEmail[$e] = $user;
            }
            $n = $this->normName((string) $user->name);
            if ($n !== '') {
                $this->usersByName[$n] = $user;
            }
        }
    }

    private function agentBranchId(int $agentId): ?int
    {
        return User::whereKey($agentId)->value('branch_id');
    }

    /**
     * Resolve up to two agents from a free-text cell like
     * "Elize Reichel, Kym Pollard".
     *
     * @return array{primary:?int, primary_name:?string, second:?int, text:string}
     */
    private function resolveAgents(string $cell): array
    {
        $cell = trim($cell);
        $blank = ['primary' => null, 'primary_name' => null, 'second' => null, 'text' => $cell];
        if ($cell === '') {
            return $blank;
        }

        $matched = [];
        $seen    = [];

        if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $cell, $m)) {
            foreach ($m[0] as $email) {
                $k = strtolower(trim($email));
                if (isset($this->usersByEmail[$k]) && !isset($seen[$this->usersByEmail[$k]->id])) {
                    $u = $this->usersByEmail[$k];
                    $matched[] = $u->id;
                    $seen[$u->id] = true;
                }
            }
        }

        $parts = preg_split('/[;,\/\|\&]+|\band\b/i', $cell) ?: [$cell];
        foreach ($parts as $part) {
            $cand = trim(preg_replace('/\([^)]*\)/', '', $part) ?? $part);
            $cand = trim(preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '', $cand) ?? $cand);
            if ($cand === '') {
                continue;
            }
            $user = $this->matchOneName($cand, $seen);
            if ($user) {
                $matched[] = $user->id;
                $seen[$user->id] = true;
            }
        }

        return [
            'primary'      => $matched[0] ?? null,
            'primary_name' => isset($matched[0]) ? ($this->usersById[$matched[0]] ?? null) : null,
            'second'       => $matched[1] ?? null,
            'text'         => $cell,
        ];
    }

    private function matchOneName(string $cand, array $seen): ?User
    {
        $nk = $this->normName($cand);
        if ($nk === '') {
            return null;
        }
        if (isset($this->usersByName[$nk]) && !isset($seen[$this->usersByName[$nk]->id])) {
            return $this->usersByName[$nk];
        }
        foreach ($this->usersByName as $nameKey => $user) {
            if ($nameKey === '' || isset($seen[$user->id])) {
                continue;
            }
            if (str_contains($nk, $nameKey) || str_contains($nameKey, $nk)) {
                return $user;
            }
        }
        return null;
    }

    private function normName(string $s): string
    {
        $s = strtolower(trim($s));
        return preg_replace('/\s+/', ' ', $s) ?? $s;
    }

    // ── Spreadsheet helpers ─────────────────────────────────────────────────

    /** @return array<string,string> logical key → column letter */
    private function buildHeaderMap(Worksheet $sheet): array
    {
        $aliases = [
            'primary_photo'  => ['primary photo', 'photo', 'image'],
            'address'        => ['address'],
            'category'       => ['category'],
            'type'           => ['type', 'property type'],
            'status'         => ['status'],
            'status_type'    => ['status type'],
            'price'          => ['price'],
            'region'         => ['region'],
            'mandate'        => ['mandate'],
            'bed'            => ['bed', 'beds', 'bedrooms'],
            'bath'           => ['bath', 'baths', 'bathrooms'],
            'garage'         => ['garage', 'garages'],
            'parking'        => ['parking'],
            'floor_size'     => ['floor size'],
            'erf_size'       => ['erf size'],
            'rates'          => ['rates'],
            'levy'           => ['levy'],
            'keywords'       => ['keywords'],
            'tags'           => ['tags'],
            'reference_code' => ['reference code', 'reference'],
            'code'           => ['code'],
            'listed'         => ['listed'],
            'modified'       => ['modified'],
            'occupation'     => ['occupation'],
            'expire'         => ['expire', 'expires', 'expiry'],
            'agents'         => ['agents', 'agent'],
        ];

        $map        = [];
        $highestCol = $sheet->getHighestColumn();
        $highestIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);

        for ($i = 1; $i <= $highestIdx; $i++) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $label  = $this->normName((string) $sheet->getCell($letter . '1')->getValue());
            if ($label === '') {
                continue;
            }
            foreach ($aliases as $key => $accepted) {
                if (isset($map[$key])) {
                    continue;
                }
                if (in_array($label, $accepted, true)) {
                    $map[$key] = $letter;
                }
            }
        }

        return $map;
    }

    private function cell(Worksheet $sheet, array $headerMap, string $key, int $row): mixed
    {
        if (!isset($headerMap[$key])) {
            return null;
        }
        return $sheet->getCell($headerMap[$key] . $row)->getValue();
    }

    /** @return array<int,string> row number → raw image bytes */
    private function buildImageMap(Worksheet $sheet): array
    {
        $images = [];
        foreach ($sheet->getDrawingCollection() as $drawing) {
            if (!preg_match('/([A-Z]+)(\d+)/', $drawing->getCoordinates(), $m)) {
                continue;
            }
            $row = (int) $m[2];
            if (isset($images[$row])) {
                continue; // first image per row wins (the Primary Photo)
            }
            $path = method_exists($drawing, 'getPath') ? $drawing->getPath() : '';
            if ($path === '') {
                continue;
            }
            $bytes = @file_get_contents($path);
            if ($bytes !== false && $bytes !== '') {
                $images[$row] = $bytes;
            }
        }
        return $images;
    }

    private function storeImage(string $bytes, int $propertyId): ?string
    {
        $disk = Storage::disk('public');
        $relative = "properties/{$propertyId}/sold-import-primary.jpg";
        if (!$disk->put($relative, $bytes)) {
            return null;
        }
        $this->downscale($disk->path($relative), 2560, 85);
        return Storage::url($relative);
    }

    /** Re-encode/resize a stored image with GD. Failures are swallowed. */
    private function downscale(string $absolute, int $maxEdge, int $quality): void
    {
        if (!function_exists('imagecreatefromstring') || !is_file($absolute)) {
            return;
        }
        $info = @getimagesize($absolute);
        if (!$info) {
            return;
        }
        [$width, $height] = $info;
        $maxSide = max($width, $height);
        if ($maxSide <= $maxEdge && ($info[2] ?? null) === IMAGETYPE_JPEG) {
            return;
        }
        $bytes = @file_get_contents($absolute);
        if ($bytes === false) {
            return;
        }
        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            return;
        }
        if ($maxSide > $maxEdge) {
            $scale = $maxEdge / $maxSide;
            $nw = max(1, (int) round($width * $scale));
            $nh = max(1, (int) round($height * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $width, $height);
            imagedestroy($src);
            $src = $dst;
        }
        @imagejpeg($src, $absolute, $quality);
        imagedestroy($src);
    }

    // ── Value parsing ───────────────────────────────────────────────────────

    /**
     * @return array{title:string, street:?string, street_number:?string, street_name:?string, suburb:?string, city:?string, province:?string}
     */
    private function parseAddress(string $raw, string $region): array
    {
        // Source lines typically end with a trailing comma ("16 Winston road,").
        $lines = array_values(array_filter(array_map(
            fn ($l) => trim($l, " \t,"),
            preg_split('/[\r\n]+/', $raw) ?: []
        )));

        $title = !empty($lines) ? trim(implode(', ', $lines)) : '';
        $title = $title !== '' ? mb_substr($title, 0, 200) : 'Imported sold property';

        $street       = $lines[0] ?? null;
        $streetNumber = null;
        $streetName   = null;
        if ($street !== null) {
            if (preg_match('/^\s*(\d+[A-Za-z]?)\s+(.+)$/', $street, $m)) {
                $streetNumber = $m[1];
                $streetName   = trim($m[2]);
            } else {
                $streetName = $street;
            }
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $region))));
        $count = count($parts);
        $province = $count >= 1 ? $parts[$count - 1] : null;
        $suburb   = $count >= 1 ? $parts[0] : null;
        $city     = $count >= 3 ? $parts[$count - 2] : ($count === 2 ? $parts[0] : null);

        return [
            'title'         => $title,
            'street'        => $street ?: null,
            'street_number' => $streetNumber,
            'street_name'   => $streetName,
            'suburb'        => $suburb ?: null,
            'city'          => $city ?: null,
            'province'      => $province ?: null,
        ];
    }

    private function mapListingType(string $statusType): string
    {
        $s = strtolower(trim($statusType));
        return (str_contains($s, 'rent') || str_contains($s, 'let')) ? 'rental' : 'sale';
    }

    private function parseFeatures(mixed $value): ?array
    {
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        $items = array_values(array_filter(array_map('trim', explode(',', $s))));
        return $items ?: null;
    }

    private function parseInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $digits = preg_replace('/[^\d]/', '', (string) $value);
        return ($digits === '' || $digits === null) ? null : (int) $digits;
    }

    private function parseFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $clean = preg_replace('/[^\d.]/', '', str_replace(',', '', (string) $value));
        return ($clean === '' || $clean === null) ? null : (float) $clean;
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            if (is_numeric($value)) {
                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value);
                return $dt ? Carbon::instance($dt)->toDateString() : null;
            }
            return Carbon::parse(trim((string) $value))->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
