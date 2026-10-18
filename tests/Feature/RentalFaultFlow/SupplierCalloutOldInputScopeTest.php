<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rentals-faults-work-orders.md section 20.8a: validation / old-input state belongs to the form that was SUBMITTED. A failed call-out save on
 * supplier A must never refill supplier B's (or the Add form's) editor, and saving B afterwards must store B's own values.
 */
final class SupplierCalloutOldInputScopeTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private AgencyServiceProvider $a;
    private AgencyServiceProvider $b;
    private AgencyServiceProvider $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Scope Agency', 'slug' => 'sa-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $this->a = $this->make('Alpha Plumbing');                                                                        // Not known
        $this->b = $this->make('Bravo Sparks', ['callout_fee_mode' => 'no']);                                            // No fee
        $this->c = $this->make('Charlie Gas', ['callout_fee_mode' => 'yes', 'callout_fee_amount' => 450, 'callout_fee_note' => 'waived if the job goes ahead']);
    }

    private function make(string $name, array $over = []): AgencyServiceProvider
    {
        return AgencyServiceProvider::withoutAgencyStamping(fn () => AgencyServiceProvider::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'name' => $name, 'specialty' => 'plumber', 'is_active' => true,
        ], $over)));
    }

    /** What one call-out editor on the page would post: its selected mode, amount and note, read from the rendered HTML by its scope. */
    private function editor(string $html, string $scope): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $x = new \DOMXPath($dom);
        $root = $x->query("//*[@data-callout-scope='{$scope}']")->item(0);
        $this->assertNotNull($root, "no call-out editor for scope {$scope}");
        $sel = $x->query(".//select[@name='callout_fee_mode']/option[@selected]", $root)->item(0);
        $amount = $x->query(".//input[@name='callout_fee_amount']", $root)->item(0);
        $note = $x->query(".//input[@name='callout_fee_note']", $root)->item(0);
        $scopeField = $x->query(".//input[@name='_callout_scope']", $root)->item(0);

        return [
            'mode' => $sel ? $sel->getAttribute('value') : 'unknown',
            'amount' => $amount ? $amount->getAttribute('value') : '',
            'note' => $note ? $note->getAttribute('value') : '',
            'scope' => $scopeField?->getAttribute('value'),
            'has_error' => $x->query(".//*[@data-callout-error]", $root)->length > 0,
        ];
    }

    private function failSaveOn(AgencyServiceProvider $p): string
    {
        $index = route('deals-v2.suppliers.index');
        $this->actingAs($this->admin)->from($index)->post(route('deals-v2.suppliers.callout-fee', $p), [
            '_callout_scope' => 'sp' . $p->id, 'callout_fee_mode' => 'yes', 'callout_fee_amount' => '', 'callout_fee_note' => 'FAILED NOTE',
        ])->assertRedirect($index)->assertSessionHasErrors('callout_fee_amount');

        return $this->actingAs($this->admin)->get($index)->assertOk()->getContent();
    }

    public function test_a_failed_save_on_one_supplier_leaves_the_other_editors_showing_their_stored_values(): void
    {
        $html = $this->failSaveOn($this->a);

        // the submitted supplier shows what was typed, and the reason, in its own editor
        $a = $this->editor($html, 'sp' . $this->a->id);
        $this->assertSame('yes', $a['mode']);
        $this->assertSame('FAILED NOTE', $a['note']);
        $this->assertTrue($a['has_error']);

        // every other supplier shows its STORED values and no error
        $b = $this->editor($html, 'sp' . $this->b->id);
        $this->assertSame(['no', '', '', false], [$b['mode'], $b['amount'], $b['note'], $b['has_error']]);
        $c = $this->editor($html, 'sp' . $this->c->id);
        $this->assertSame('yes', $c['mode']);
        $this->assertEquals(450, (float) $c['amount']);
        $this->assertSame('waived if the job goes ahead', $c['note']);
        $this->assertFalse($c['has_error']);
        $this->assertStringNotContainsString('FAILED NOTE', explode('data-callout-scope="sp' . $this->b->id . '"', $html)[1] ?? '', 'the failed note leaked into a later editor');

        // and the Add form is untouched too
        $add = $this->editor($html, 'sp');
        $this->assertSame(['unknown', ''], [$add['mode'], $add['note']]);
        $this->assertFalse($add['has_error']);
    }

    public function test_saving_another_supplier_after_a_failure_stores_that_suppliers_own_values(): void
    {
        $html = $this->failSaveOn($this->a);

        // press Save call-out fee on B and on C exactly as the page now shows them
        foreach ([$this->b, $this->c] as $p) {
            $e = $this->editor($html, 'sp' . $p->id);
            $this->actingAs($this->admin)->post(route('deals-v2.suppliers.callout-fee', $p), [
                '_callout_scope' => $e['scope'], 'callout_fee_mode' => $e['mode'], 'callout_fee_amount' => $e['amount'], 'callout_fee_note' => $e['note'],
            ])->assertSessionHasNoErrors();
        }

        $b = AgencyServiceProvider::withoutGlobalScopes()->find($this->b->id);
        $this->assertSame('no', $b->callout_fee_mode);
        $this->assertNull($b->callout_fee_amount);
        $c = AgencyServiceProvider::withoutGlobalScopes()->find($this->c->id);
        $this->assertSame('yes', $c->callout_fee_mode);
        $this->assertEquals(450, (float) $c->callout_fee_amount);
        $this->assertSame('waived if the job goes ahead', $c->callout_fee_note);
        // A's failed save wrote nothing
        $this->assertSame('unknown', AgencyServiceProvider::withoutGlobalScopes()->find($this->a->id)->callout_fee_mode);
        // and nothing was written to anyone's call-out history by those two unchanged saves
        $this->assertSame(0, \DB::table('agency_service_provider_callout_history')->whereIn('service_provider_id', [$this->b->id, $this->c->id])->where('source', 'directory')->count());
    }

    public function test_a_failed_add_refills_only_the_add_form(): void
    {
        $index = route('deals-v2.suppliers.index');
        $this->actingAs($this->admin)->from($index)->post(route('deals-v2.suppliers.store'), [
            '_callout_scope' => 'sp', 'name' => 'New Firm', 'specialty' => 'plumber', 'callout_fee_mode' => 'yes', 'callout_fee_amount' => '', 'callout_fee_note' => 'ADD NOTE',
        ])->assertSessionHasErrors('callout_fee_amount');
        $html = $this->actingAs($this->admin)->get($index)->getContent();

        $add = $this->editor($html, 'sp');
        $this->assertSame(['yes', 'ADD NOTE', true], [$add['mode'], $add['note'], $add['has_error']]);
        foreach ([$this->a, $this->b, $this->c] as $p) {
            $e = $this->editor($html, 'sp' . $p->id);
            $this->assertNotSame('ADD NOTE', $e['note']);
            $this->assertFalse($e['has_error']);
        }
        $this->assertSame('no', $this->editor($html, 'sp' . $this->b->id)['mode']);
    }

    public function test_every_call_out_editor_carries_its_own_scope_and_no_other_directory_view_reads_old_input(): void
    {
        $html = $this->actingAs($this->admin)->get(route('deals-v2.suppliers.index'))->getContent();
        $scopes = [];
        foreach ([$this->a, $this->b, $this->c] as $p) {
            $scopes[] = $this->editor($html, 'sp' . $p->id)['scope'];
        }
        $scopes[] = $this->editor($html, 'sp')['scope'];
        $this->assertSame(['sp' . $this->a->id, 'sp' . $this->b->id, 'sp' . $this->c->id, 'sp'], $scopes);
        $this->assertCount(4, array_unique($scopes));

        // the bug class: the per-row editors (kind, service types, address, attorney role, contacts) and the popups never refill from old(); the call-out
        // partial does, but ONLY when the scope that comes back is its own.
        foreach (glob(base_path('resources/views/deals-v2/suppliers/*.blade.php')) as $file) {
            $src = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($file));   // comments may talk about old()
            if (basename($file) === '_callout-fields.blade.php') {
                // every old() in the partial is either the scope key itself or sits behind $submitted ? ... :
                $this->assertSame(0, preg_match_all('/old\((?!\'_callout_scope\')/', preg_replace('/\$submitted \? old\([^;]*;/', '', $src)), 'unscoped old() in the call-out partial');
                $this->assertStringContainsString("old('_callout_scope') === \$scope", $src);
                continue;
            }
            $this->assertStringNotContainsString('old(', $src, basename($file) . ' reads old input outside the scoped partial');
        }
        $this->assertStringNotContainsString('old(', file_get_contents(base_path('resources/views/partials/contractor-popups.blade.php')));
    }
}
