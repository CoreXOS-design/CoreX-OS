<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rentals-faults-work-orders.md section 20.8: the Supplier Directory is ONE line per supplier with an Open button that expands the
 * details in place, equal-sized row buttons, Set preferred / Deactivate as buttons inside the details, a neutral sub-heading, and a
 * "Suppliers & contractors" link in the Rentals menu.
 */
final class SupplierDirectoryListTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'List Agency', 'slug' => 'la-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
    }

    private function make(string $name, array $over = []): AgencyServiceProvider
    {
        return AgencyServiceProvider::withoutAgencyStamping(fn () => AgencyServiceProvider::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'name' => $name, 'specialty' => 'plumber', 'is_active' => true,
        ], $over)));
    }

    private function page(string $query = ''): string
    {
        return $this->actingAs($this->admin)->get(route('deals-v2.suppliers.index') . $query)->assertOk()->getContent();
    }

    public function test_each_supplier_is_one_summary_line_with_an_open_button_and_closed_details(): void
    {
        $a = $this->make('Alpha Plumbing', ['company' => 'Alpha CC', 'email' => 'alpha@example.test', 'phone' => '0821110000']);
        $b = $this->make('Bravo Sparks', ['specialty' => 'electrician']);
        $html = $this->page();

        $this->assertSame(2, substr_count($html, 'data-supplier-row="'));
        $this->assertSame(2, substr_count($html, 'data-open-supplier'));
        // the summary line carries name, kind, specialty, contact, call-out, status
        $this->assertStringContainsString('data-supplier-kind="contractor"', $html);
        $this->assertStringContainsString('data-supplier-contact', $html);
        $this->assertStringContainsString('data-callout-cell="unknown"', $html);
        $this->assertStringContainsString('alpha@example.test', $html);
        // details exist for each, hidden until Open is pressed (x-show + x-cloak keyed on the open row)
        foreach ([$a, $b] as $p) {
            $this->assertMatchesRegularExpression('/<tr id="provider-' . $p->id . '-details" x-show="openId === ' . $p->id . '" x-cloak/', $html);
            $this->assertStringContainsString('data-callout-editor="' . $p->id . '"', $html);
        }
        // one open at a time: a single openId
        $this->assertStringContainsString('openId: 0', $html);
    }

    public function test_a_supplier_can_be_opened_from_the_address_bar_and_the_fault_page_supplier_opens_by_itself(): void
    {
        $a = $this->make('Alpha Plumbing');
        $html = $this->page('?open=' . $a->id);
        $this->assertStringContainsString('openId: ' . $a->id, $html);
    }

    public function test_row_buttons_are_equal_sized_and_set_preferred_and_deactivate_are_buttons_in_the_details(): void
    {
        $a = $this->make('Alpha Plumbing');
        $gone = $this->make('Zulu Old', ['is_active' => false]);
        $html = $this->page();

        // Open and Restore share one class and one width
        $this->assertSame(1, preg_match('/class="(corex-btn-outline text-xs justify-center)" style="min-width: 6rem;"\s*:aria-expanded[^>]*data-open-supplier/s', $html));
        $this->assertSame(1, preg_match('/class="(corex-btn-outline text-xs justify-center)" style="min-width: 6rem;" data-restore-supplier/', $html));
        // inactive suppliers are restored from the line and have no details
        $this->assertStringNotContainsString('provider-' . $gone->id . '-details', $html);

        // Set preferred and Deactivate: real, equal-sized buttons in the details, no text links
        $this->assertStringContainsString('class="corex-btn-outline text-xs justify-center" style="min-width: 9rem;" data-set-preferred', $html);
        $this->assertMatchesRegularExpression('/class="corex-btn-outline text-xs justify-center" style="min-width: 9rem; color: var\(--ds-crimson\);[^"]*" data-deactivate-supplier/', $html);
        $this->assertStringNotContainsString('hover:underline" style="color: var(--ds-crimson);">Deactivate', $html);
        // Deactivate asks first (the app's own dialog) and never deletes
        $this->assertMatchesRegularExpression('/<form[^>]*deactivate[^>]*data-confirm="Deactivate Alpha Plumbing\?[^"]*"[^>]*data-confirm-danger/s', $html);
        $this->assertStringNotContainsString("confirm('Deactivate", $html);
    }

    public function test_deactivate_still_soft_deactivates_and_restore_brings_it_back(): void
    {
        $a = $this->make('Alpha Plumbing');
        $this->actingAs($this->admin)->post(route('deals-v2.suppliers.deactivate', $a))->assertRedirect();
        $this->assertFalse((bool) AgencyServiceProvider::withoutGlobalScopes()->find($a->id)->is_active);
        $this->assertNotNull(AgencyServiceProvider::withoutGlobalScopes()->find($a->id), 'never a hard delete');
        $this->actingAs($this->admin)->post(route('deals-v2.suppliers.activate', $a))->assertRedirect();
        $this->assertTrue((bool) AgencyServiceProvider::withoutGlobalScopes()->find($a->id)->is_active);
    }

    public function test_the_sub_heading_is_neutral(): void
    {
        $html = $this->page();
        $this->assertStringContainsString('Service providers and contractors used on deals and rentals.', $html);
        $this->assertStringNotContainsString('entomologist', strtolower(strip_tags(explode('Add provider', $html)[0])));
        $this->assertStringNotContainsString('bond originator', $html);
    }

    public function test_the_kind_select_lives_in_the_details_and_the_line_shows_the_kind_as_text(): void
    {
        $this->make('Bravo Caretaking', ['kind' => 'caretaker', 'specialty' => 'other']);
        $html = $this->page();
        $this->assertMatchesRegularExpression('/data-supplier-kind="caretaker">Caretaker \/ complex manager</', $html);
        $this->assertStringContainsString('data-supplier-kind-select', $html);
    }

    public function test_the_filter_form_and_paging_keep_the_rentals_menu_marker(): void
    {
        $this->make('Alpha Plumbing');
        $html = $this->page('?nav=rentals');
        $this->assertStringContainsString('<input type="hidden" name="nav" value="rentals">', $html);
        $this->assertStringNotContainsString('<input type="hidden" name="nav" value="rentals">', $this->page());
    }

    public function test_the_rentals_menu_has_a_suppliers_and_contractors_link_to_the_same_screen(): void
    {
        $html = $this->page();
        $this->assertStringContainsString('Suppliers &amp; contractors</a>', $html);
        $this->assertStringContainsString(route('deals-v2.suppliers.index', ['nav' => 'rentals']), html_entity_decode($html));
        // the Deal Register Settings link is still there
        $this->assertStringContainsString('>Supplier Directory</a>', $html);

        // reached from Rentals: the Rentals link is the highlighted one and the Rentals panel is the open one
        $from = $this->page('?nav=rentals');
        $this->assertMatchesRegularExpression('/corex-nav-subitem active">Suppliers &amp; contractors</', $from);
        $this->assertDoesNotMatchRegularExpression('/corex-nav-subitem active">Supplier Directory</', $from);
        $this->assertMatchesRegularExpression('/corex-nav-panel is-open"[^>]*>\s*<button[^>]*corex-nav-back[^>]*>.*?<div class="corex-nav-panel-title">Rentals</s', $from);
    }

    public function test_the_new_link_uses_the_same_permission_as_the_page(): void
    {
        $blade = file_get_contents(base_path('resources/views/layouts/corex-sidebar.blade.php'));
        $at = strpos($blade, 'Suppliers &amp; contractors</a>');
        $this->assertNotFalse($at);
        $before = substr($blade, max(0, $at - 700), 700);
        $this->assertStringContainsString("@permission('deals_v2.manage_suppliers')", $before);
        $this->assertStringContainsString("permission:deals_v2.manage_suppliers", file_get_contents(base_path('routes/web.php')));
    }
}
