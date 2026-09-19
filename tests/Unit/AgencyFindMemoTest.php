<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Agency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026-09-10 — Agency::find()'s static per-process memo ($findMemo, added
 * 2026-08-23 for the MIC speed-round perf fix) was never invalidated on
 * save/delete, so any update reached via a path other than the memoized
 * find() itself (e.g. Agency::findOrFail($id)->save()) left later
 * Agency::find($id) calls in the same request/process serving pre-update
 * data. Reproduced via PropertyFilterPersistenceTest::
 * test_updating_properties_sort_persists_mode_and_order — the DB write was
 * correct, only the memoized read was stale.
 */
final class AgencyFindMemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_find_reflects_a_save_made_through_a_different_instance(): void
    {
        $id = $this->makeAgency();

        Agency::find($id); // warm the memo, as any of the ~30 real call sites would.

        Agency::findOrFail($id)->update(['name' => 'Updated Via findOrFail']);

        $this->assertSame('Updated Via findOrFail', Agency::find($id)->name);
    }

    public function test_find_returns_null_after_the_memoised_row_is_deleted(): void
    {
        $id = $this->makeAgency();

        $warm = Agency::find($id);
        $this->assertNotNull($warm);

        Agency::findOrFail($id)->delete();

        $this->assertNull(Agency::find($id));
    }

    public function test_find_reflects_a_restore_after_the_memoised_row_was_deleted(): void
    {
        $id = $this->makeAgency();

        Agency::find($id);
        Agency::findOrFail($id)->delete();
        $this->assertNull(Agency::find($id));

        Agency::withTrashed()->findOrFail($id)->restore();

        $this->assertNotNull(Agency::find($id));
    }

    private function makeAgency(): int
    {
        return (int) Agency::create([
            'name' => 'Memo Test '.Str::random(6),
            'slug' => 'memo-test-'.Str::random(8),
        ])->id;
    }
}
