<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Exceptions\InvalidPropertyStatusException;
use App\Models\Property;
use App\Models\User;
use App\Rules\ValidPropertyStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-307 — status-membership hardening.
 *
 * The 2903 incident: a write set an unrecognised / ineffective property status and
 * nothing caught it — the property kept syndicating while everyone believed it was
 * withdrawn. The fix is a validity gate at the LAST place every path funnels through
 * (PropertyObserver::saving), backed by a clean 422 at the request layers. This test
 * proves no path can persist an out-of-vocabulary status, and that the legitimate
 * vocabulary (case-insensitive) still saves untouched.
 */
final class PropertyStatusMembershipGuardTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $agentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(5), 'slug' => 'c-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->agentId = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent',
        ])->id;
    }

    // ── The model backstop (covers EVERY path: job / console / import / direct save) ──

    public function test_saving_an_unrecognised_status_is_refused(): void
    {
        $p = $this->property(['status' => 'active']);

        $p->status = 'widthdrawn'; // the exact shape of the bug: a typo that syndicates on
        $this->expectException(InvalidPropertyStatusException::class);
        $p->save();
    }

    public function test_the_refused_write_never_reaches_the_database(): void
    {
        $p = $this->property(['status' => 'active']);

        try {
            $p->status = 'not_a_status';
            $p->save();
        } catch (InvalidPropertyStatusException $e) {
            // expected
        }

        $this->assertSame('active', $p->fresh()->status,
            'the invalid status must not have been persisted');
    }

    public function test_an_empty_string_status_is_refused(): void
    {
        $p = $this->property(['status' => 'active']);

        $p->status = '';
        $this->expectException(InvalidPropertyStatusException::class);
        $p->save();
    }

    public function test_a_creating_write_with_a_bad_status_is_refused(): void
    {
        $this->expectException(InvalidPropertyStatusException::class);
        $this->property(['status' => 'garbage']);
    }

    // ── The legitimate vocabulary still saves, case-insensitively ──

    /** @dataProvider validStatuses */
    public function test_valid_statuses_persist(string $status): void
    {
        $p = $this->property(['status' => 'active']);
        $p->status = $status;
        $p->save();

        $this->assertSame($status, $p->fresh()->status);
    }

    public static function validStatuses(): array
    {
        return [
            ['sold'], ['Sold'], ['WITHDRAWN'], ['under_offer'],
            ['Rented'], ['for_sale'], ['on_show'], ['expired'],
        ];
    }

    public function test_a_null_status_is_left_to_the_column_default(): void
    {
        // Not dirty+non-null → the guard leaves it alone; the DB default stands.
        $p = $this->property(['status' => 'active']);
        $p->price = 1_600_000; // save without touching status
        $p->save();

        $this->assertSame('active', $p->fresh()->status);
    }

    // ── isValidStatus unit truth ──

    public function test_is_valid_status_matrix(): void
    {
        $this->assertTrue(Property::isValidStatus('sold'));
        $this->assertTrue(Property::isValidStatus('Sold'));
        $this->assertTrue(Property::isValidStatus('  under_offer  '));
        $this->assertFalse(Property::isValidStatus('foo'));
        $this->assertFalse(Property::isValidStatus(''));
        $this->assertFalse(Property::isValidStatus(null));
    }

    // ── The request-layer rule gives a clean 422 instead of a 500 ──

    public function test_rule_rejects_garbage_but_passes_valid_and_null(): void
    {
        $rule = ['status' => ['nullable', new ValidPropertyStatus]];

        $this->assertTrue(Validator::make(['status' => 'sold'], $rule)->passes());
        $this->assertTrue(Validator::make(['status' => 'Withdrawn'], $rule)->passes());
        $this->assertTrue(Validator::make(['status' => null], $rule)->passes());
        $this->assertFalse(Validator::make(['status' => 'made_up'], $rule)->passes());

        // An empty string is SKIPPED at the request layer (Laravel does not run a
        // non-implicit rule object on an empty value, and ConvertEmptyStringsToNull
        // turns a real request's '' into null → column default). The model guard is
        // the backstop for a raw '' write — proven by test_an_empty_string_status_is_refused.
        $this->assertTrue(Validator::make(['status' => ''], $rule)->passes());
    }

    private function property(array $attrs): Property
    {
        $base = [
            'external_id' => 'AT307-' . Str::random(8), 'title' => 'Test',
            'suburb' => 'Uvongo', 'price' => 1_500_000, 'property_type' => 'house',
            'beds' => 3, 'status' => 'active', 'is_demo' => false,
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'agent_id' => $this->agentId,
        ];

        $p = new Property();
        foreach (array_merge($base, $attrs) as $k => $v) {
            $p->{$k} = $v;
        }
        $p->save();

        return $p->fresh();
    }
}
