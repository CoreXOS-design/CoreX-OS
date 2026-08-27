<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Services\WebTemplateDataService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * cc1's finding on 49d8de43b (Johan, 2026-08-25): a recipient promoted to an
 * ordinary SignatureRequest solely so a deceased party's slot binding has a
 * real substitute (bindSlotToContact(), wizard.blade.php) was ALSO being
 * swept into WebTemplateDataService::resolveFieldGroupValue()'s plain
 * "every recipient of this role" list — naming the same person twice in the
 * document body: once correctly as "...herein represented by Hendrik...",
 * once more as though Hendrik were an independent co-seller.
 *
 * Covers the two new private helpers directly via reflection (no DB
 * dependency — WebTemplateDataService has no constructor): the substitute
 * exclusion, and Johan's join rule (comma between, "and" before the last)
 * that replaced the old bare `implode(' and', ...)`. The full end-to-end
 * body text — before and after, real send path, rolled back — is proven
 * separately per Johan's verification protocol.
 */
final class DeceasedSubstituteBodyListTest extends TestCase
{
    private function excludeSubstituteOnly(array $recipients): array
    {
        $m = new ReflectionMethod(WebTemplateDataService::class, 'excludeSubstituteOnlyRecipients');
        $m->setAccessible(true);

        return $m->invoke(app(WebTemplateDataService::class), $recipients);
    }

    private function joinWithAnd(array $items): string
    {
        $m = new ReflectionMethod(WebTemplateDataService::class, 'joinPartiesWithAnd');
        $m->setAccessible(true);

        return $m->invoke(app(WebTemplateDataService::class), $items);
    }

    /** The exact bug: Hendrik carries _deceased_substitute_for → excluded from the plain role list. */
    public function test_a_deceased_substitute_row_is_excluded(): void
    {
        $recipients = [
            ['name' => 'Petrus', 'role' => 'seller', '_recipient_local_key' => 'petrus-key'],
            ['name' => 'Susan', 'role' => 'seller', '_recipient_local_key' => 'susan-key'],
            ['name' => 'Hendrik', 'role' => 'seller', '_recipient_local_key' => 'hendrik-key', '_deceased_substitute_for' => 'petrus-key'],
        ];

        $out = $this->excludeSubstituteOnly($recipients);

        $this->assertCount(2, $out);
        $this->assertSame(['Petrus', 'Susan'], array_column($out, 'name'), 'Hendrik must be excluded — he already appears inside Petrus\'s own clause.');
    }

    /** No substitute anywhere — every ordinary recipient passes through untouched. */
    public function test_ordinary_recipients_all_pass_through(): void
    {
        $recipients = [
            ['name' => 'Anna', 'role' => 'seller'],
            ['name' => 'Ben', 'role' => 'seller'],
            ['name' => 'Chris', 'role' => 'seller'],
        ];

        $out = $this->excludeSubstituteOnly($recipients);

        $this->assertCount(3, $out);
    }

    /** Johan's join rule: comma between, "and" before the last — the 3+ case cc1's evidence showed broken. */
    public function test_three_items_join_with_comma_then_and(): void
    {
        $this->assertSame(
            'Anna Three (ID: 1), Ben Three (ID: 2) and Chris Three (ID: 3)',
            $this->joinWithAnd(['Anna Three (ID: 1)', 'Ben Three (ID: 2)', 'Chris Three (ID: 3)'])
        );
    }

    /** Two items: no comma needed, just "and". */
    public function test_two_items_join_with_and_only(): void
    {
        $this->assertSame('Dee Two (ID: 4) and Eve Two (ID: 5)', $this->joinWithAnd(['Dee Two (ID: 4)', 'Eve Two (ID: 5)']));
    }

    /** One item: bare, no join at all. */
    public function test_one_item_returns_bare(): void
    {
        $this->assertSame('Solo Seller (ID: 6)', $this->joinWithAnd(['Solo Seller (ID: 6)']));
    }

    /** Four items: comma-comma-and, not a second "and". */
    public function test_four_items_join_with_commas_then_and(): void
    {
        $this->assertSame('A, B, C and D', $this->joinWithAnd(['A', 'B', 'C', 'D']));
    }
}
