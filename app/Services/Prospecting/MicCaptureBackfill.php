<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * MIC capture lane, step 3.5 (.ai/specs/mic-work-tab.md §11.2): three one-off back-fills of facts the old extension
 * never sent but that CoreX already holds somewhere. Each runs as a dry run first (counts only), then --apply, which
 * journals every row it changes (table, key, column, old, new) so it can be undone from the journal as well as the DB dump.
 *
 *   p24-groups   P24's own "Listed by N Estate Agencies" statement, read from the stored raw search pages
 *   floor-sizes  property_size_m2 from portal_listings.current_fields_json.size_m2 (the tile's FLOOR size) where empty
 *   sale-agency  P24 owner adverts stored with the owner icon's alt text "Sale" as the agency -> owner, agency blank
 *
 * Only fills what is empty / corrects the one known defect; never overwrites a value an agent or a later capture set.
 */
final class MicCaptureBackfill
{
    public const STEPS = ['p24-groups', 'floor-sizes', 'sale-agency'];

    /** @var resource|null */
    private $journal = null;

    public function journalTo(?string $path): void
    {
        if (is_resource($this->journal)) {
            fclose($this->journal);
        }
        $this->journal = $path !== null ? fopen($path, 'ab') : null;
    }

    private function log(string $table, string $key, string $column, mixed $old, mixed $new): void
    {
        if (is_resource($this->journal)) {
            fwrite($this->journal, json_encode(['table' => $table, 'key' => $key, 'column' => $column, 'old' => $old, 'new' => $new], JSON_UNESCAPED_UNICODE) . "\n");
        }
    }

    // ── p24-groups ───────────────────────────────────────────────────────────────────────────────

    /**
     * Every "Listed by N Estate Agencies" group on a P24 results page: [group id => ['size' => ?int, 'numbers' => list<string>]].
     * The markup (seen on stored pages and in Chrome, 31 Oct): <span class="js_groupedListingsLink" data-listing-group-text="2 Estate Agencies"
     * data-group-listing-id="1815781" data-href="/Results/GroupedListings/1815781?...&listingNumbers=%5b116219508%2c117029515%5d">.
     *
     * @return array<string, array{size: ?int, numbers: list<string>}>
     */
    public static function parseGroups(string $html): array
    {
        $groups = [];
        if (! preg_match_all('/<(?:span|div|a)\b[^>]*\bdata-group-listing-id\s*=\s*"(\d{1,20})"[^>]*>/is', $html, $tags, PREG_SET_ORDER)) {
            return $groups;
        }
        foreach ($tags as $m) {
            $tag = $m[0];
            $id = $m[1];
            $href = preg_match('/\bdata-href\s*=\s*"([^"]*)"/i', $tag, $h) ? urldecode(html_entity_decode($h[1])) : '';
            $numbers = [];
            if (preg_match('/listingNumbers[=\/]([^&#?]*)/i', $href, $n)) {
                preg_match_all('/\d{6,12}/', $n[1], $nums);
                $numbers = array_values(array_unique($nums[0]));
            }
            $size = preg_match('/\bdata-listing-group-text\s*=\s*"(\d+)\s+Estate/i', $tag, $s) ? (int) $s[1] : null;
            if ($numbers === []) {
                continue;
            }
            $groups[$id] = ['size' => $size, 'numbers' => array_values(array_unique(array_merge($groups[$id]['numbers'] ?? [], $numbers)))];
        }

        return $groups;
    }

    /** @param list<int> $exclude agencies left out (test / demo) */
    public function p24Groups(bool $apply, array $exclude = [20]): array
    {
        $stats = ['captures_read' => 0, 'captures_unreadable' => 0, 'pages_with_groups' => 0, 'groups' => 0, 'member_numbers' => 0,
            'numbers_in_two_groups' => 0, 'member_rows_new' => 0, 'member_rows_already_there' => 0,
            'adverts_getting_group_id' => 0, 'adverts_already_have_group_id' => 0, 'pool_rows_getting_group_id' => 0];

        /** @var array<string, array{size: ?int, numbers: list<string>, first: ?string, last: ?string}> $all */
        $all = [];
        // any stored Property24 page: a results page filed under another page type still carries the same group markup
        DB::table('portal_captures')->where('source_site', 'like', '%property24%')->whereNotNull('raw_html_path')
            ->orderBy('id')->select('id', 'raw_html_path', 'created_at')->chunkById(50, function ($rows) use (&$all, &$stats) {
                foreach ($rows as $r) {
                    $html = $this->readCapture((string) $r->raw_html_path);
                    if ($html === null) {
                        $stats['captures_unreadable']++;

                        continue;
                    }
                    $stats['captures_read']++;
                    $found = self::parseGroups($html);
                    if ($found !== []) {
                        $stats['pages_with_groups']++;
                    }
                    foreach ($found as $gid => $g) {
                        $cur = $all[$gid] ?? ['size' => null, 'numbers' => [], 'first' => $r->created_at, 'last' => $r->created_at];
                        $cur['size'] = $g['size'] ?? $cur['size'];
                        $cur['numbers'] = array_values(array_unique(array_merge($cur['numbers'], $g['numbers'])));
                        $cur['last'] = $r->created_at;
                        $all[$gid] = $cur;
                    }
                }
            });

        $stats['groups'] = count($all);
        $numberGroups = [];   // listing number => [group ids], newest group last
        foreach ($all as $gid => $g) {
            $stats['member_numbers'] += count($g['numbers']);
            foreach ($g['numbers'] as $n) {
                $numberGroups[$n][] = (string) $gid;
            }
        }
        $stats['numbers_in_two_groups'] = count(array_filter($numberGroups, fn ($ids) => count($ids) > 1));

        // member rows
        $existing = DB::table('p24_group_members')->select('p24_group_id', 'listing_number')->get()->mapWithKeys(fn ($r) => [$r->p24_group_id . '|' . $r->listing_number => true])->all();
        $newRows = [];
        foreach ($all as $gid => $g) {
            foreach ($g['numbers'] as $n) {
                if (isset($existing[$gid . '|' . $n])) {
                    $stats['member_rows_already_there']++;

                    continue;
                }
                $newRows[] = [
                    'p24_group_id' => (string) $gid, 'listing_number' => $n, 'group_size' => $g['size'] ?? count($g['numbers']),
                    'first_seen_at' => $g['first'], 'seen_at' => $g['last'], 'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
        $stats['member_rows_new'] = count($newRows);

        // which adverts / pool rows learn their group (the group that listed the number most recently = the last in its list)
        $advertGroup = [];
        foreach ($numberGroups as $n => $ids) {
            $advertGroup[$n] = end($ids);
        }
        $advertUpdates = [];
        $poolUpdates = [];
        foreach (array_chunk(array_keys($advertGroup), 1000) as $chunk) {
            $refs = array_map(fn ($n) => 'P24-' . $n, $chunk);
            DB::table('prospecting_listings')->whereIn('portal_ref', $refs)->where('portal_source', 'p24')
                ->when($exclude !== [], fn ($q) => $q->whereNotIn('agency_id', $exclude))->select('id', 'portal_ref', 'p24_group_id')->get()
                ->each(function ($r) use ($advertGroup, &$advertUpdates, &$stats) {
                    if ($r->p24_group_id !== null && $r->p24_group_id !== '') {
                        $stats['adverts_already_have_group_id']++;

                        return;
                    }
                    $advertUpdates[(int) $r->id] = $advertGroup[substr($r->portal_ref, 4)];
                });
            DB::table('portal_advert_facts')->whereIn('canonical_ref', array_map(fn ($n) => 'p24:' . $n, $chunk))->whereNull('p24_group_id')
                ->select('id', 'canonical_ref')->get()->each(function ($r) use ($advertGroup, &$poolUpdates) {
                    $poolUpdates[(int) $r->id] = $advertGroup[substr($r->canonical_ref, 4)];
                });
        }
        $stats['adverts_getting_group_id'] = count($advertUpdates);
        $stats['pool_rows_getting_group_id'] = count($poolUpdates);

        if ($apply) {
            DB::transaction(function () use ($newRows, $advertUpdates, $poolUpdates) {
                foreach (array_chunk($newRows, 500) as $chunk) {
                    DB::table('p24_group_members')->insertOrIgnore($chunk);
                    foreach ($chunk as $r) {
                        $this->log('p24_group_members', $r['p24_group_id'] . '|' . $r['listing_number'], '(row)', null, 'inserted');
                    }
                }
                foreach ($advertUpdates as $id => $gid) {
                    DB::table('prospecting_listings')->where('id', $id)->whereNull('p24_group_id')->update(['p24_group_id' => $gid]);
                    $this->log('prospecting_listings', (string) $id, 'p24_group_id', null, $gid);
                }
                foreach ($poolUpdates as $id => $gid) {
                    DB::table('portal_advert_facts')->where('id', $id)->whereNull('p24_group_id')->update(['p24_group_id' => $gid]);
                    $this->log('portal_advert_facts', (string) $id, 'p24_group_id', null, $gid);
                }
            });
        }

        return $stats;
    }

    private function readCapture(string $path): ?string
    {
        foreach (['local', null] as $disk) {
            try {
                $d = $disk === null ? Storage::build(['driver' => 'local', 'root' => storage_path('app')]) : Storage::disk($disk);
                if ($d->exists($path)) {
                    return $d->get($path);
                }
            } catch (\Throwable) {
                // try the next root
            }
        }

        return null;
    }

    // ── floor-sizes ──────────────────────────────────────────────────────────────────────────────

    public function floorSizes(bool $apply, array $exclude = [20]): array
    {
        $base = fn () => DB::table('portal_listings as pl')
            ->join('prospecting_listings as p', function ($j) {
                $j->on('p.agency_id', '=', 'pl.agency_id')->on('p.portal_ref', '=', DB::raw("CONCAT('P24-', pl.portal_listing_id)"))->where('p.portal_source', '=', 'p24');
            })
            ->where('pl.source_site', 'like', '%property24%')->whereNull('p.deleted_at')
            ->whereRaw("JSON_EXTRACT(pl.current_fields_json, '$.size_m2') IS NOT NULL")
            ->when($exclude !== [], fn ($q) => $q->whereNotIn('p.agency_id', $exclude));

        $stats = [
            'portal_listings_with_floor_size' => (int) DB::table('portal_listings')->where('source_site', 'like', '%property24%')
                ->whereRaw("JSON_EXTRACT(current_fields_json, '$.size_m2') IS NOT NULL")->count(),
            'matched_to_a_mic_advert' => (int) $base()->count(),
            'mic_advert_already_has_floor_size' => (int) $base()->whereNotNull('p.property_size_m2')->count(),
            'floor_sizes_to_fill' => 0, 'skipped_implausible' => 0, 'also_equal_to_erf_size' => 0,
        ];

        $fill = [];
        $base()->whereNull('p.property_size_m2')->select('p.id', 'p.erf_size_m2', DB::raw("CAST(JSON_UNQUOTE(JSON_EXTRACT(pl.current_fields_json, '$.size_m2')) AS DECIMAL(12,2)) as size"))
            ->orderBy('p.id')->get()->each(function ($r) use (&$fill, &$stats) {
                $size = (float) $r->size;
                if ($size < 10 || $size > 100000) {
                    $stats['skipped_implausible']++;

                    return;
                }
                if ($r->erf_size_m2 !== null && abs((float) $r->erf_size_m2 - $size) < 0.01) {
                    $stats['also_equal_to_erf_size']++;
                }
                $fill[(int) $r->id] = $size;
            });
        $stats['floor_sizes_to_fill'] = count($fill);

        if ($apply) {
            DB::transaction(function () use ($fill) {
                foreach ($fill as $id => $size) {
                    $n = DB::table('prospecting_listings')->where('id', $id)->whereNull('property_size_m2')->update(['property_size_m2' => $size]);
                    if ($n) {
                        $this->log('prospecting_listings', (string) $id, 'property_size_m2', null, $size);
                    }
                }
            });
        }

        return $stats;
    }

    // ── sale-agency ──────────────────────────────────────────────────────────────────────────────

    public function saleAgency(bool $apply, array $exclude = [20]): array
    {
        $listings = fn () => DB::table('prospecting_listings')->where('portal_source', 'p24')->whereRaw("LOWER(TRIM(agency_name)) = 'sale'")
            ->when($exclude !== [], fn ($q) => $q->whereNotIn('agency_id', $exclude));
        $pool = fn () => DB::table('portal_advert_facts')->where('portal', 'p24')->whereRaw("LOWER(TRIM(listing_agency_name)) = 'sale'");

        $stats = [
            'adverts_stored_with_agency_sale' => (int) $listings()->count(),
            'of_which_active' => (int) $listings()->where('is_active', 1)->whereNull('deleted_at')->count(),
            'pool_rows_with_agency_sale' => (int) $pool()->count(),
        ];

        if ($apply) {
            DB::transaction(function () use ($listings, $pool) {
                $listings()->select('id', 'agency_name', 'advertiser_type')->orderBy('id')->get()->each(function ($r) {
                    DB::table('prospecting_listings')->where('id', $r->id)->update(['advertiser_type' => 'owner', 'agency_name' => null]);
                    $this->log('prospecting_listings', (string) $r->id, 'agency_name', $r->agency_name, null);
                    $this->log('prospecting_listings', (string) $r->id, 'advertiser_type', $r->advertiser_type, 'owner');
                });
                $pool()->select('id', 'listing_agency_name', 'advertiser_type')->orderBy('id')->get()->each(function ($r) {
                    DB::table('portal_advert_facts')->where('id', $r->id)->update(['advertiser_type' => 'owner', 'listing_agency_name' => null]);
                    $this->log('portal_advert_facts', (string) $r->id, 'listing_agency_name', $r->listing_agency_name, null);
                    $this->log('portal_advert_facts', (string) $r->id, 'advertiser_type', $r->advertiser_type, 'owner');
                });
            });
        }

        return $stats;
    }
}
