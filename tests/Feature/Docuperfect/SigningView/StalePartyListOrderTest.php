<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\ESignWizardController;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AT-498 — flow 116: the proxy holder reads first in the document, but the Step 6 preview still showed the OLD order because Fill & Review had
 * saved the party list (a field_group_display value) before the order rule existed, and a saved value overrides the freshly derived one.
 * The preview, step 5's own form and every sent document now prefer the freshly derived list when the saved one has the same parties.
 */
final class StalePartyListOrderTest extends TestCase
{
    private const OLD = 'Paula Partner (ID: 7808085009081), herein represented by Peter Proxy (ID: 7505055019088) in terms of a proxy; Sam Separate (ID: 8203035009085); and Peter Proxy (ID: 7505055019088)';
    private const FRESH = 'Peter Proxy (ID: 7505055019088); Paula Partner (ID: 7808085009081), herein represented by Peter Proxy (ID: 7505055019088) in terms of a proxy; and Sam Separate (ID: 8203035009085)';

    private function overlay(array $data, array $stepData): array
    {
        $m = new ReflectionMethod(ESignWizardController::class, 'overlayFillReviewValues');
        $m->setAccessible(true);

        return $m->invoke(app(ESignWizardController::class), $data, $stepData, null);
    }

    private function step(string $stored, string $type = 'field_group_display'): array
    {
        return [
            'fields' => [['id' => 'tag-sellers', 'field_name' => 'seller_full', 'type' => $type]],
            'fill_review' => ['fieldValues' => ['tag-sellers' => $stored]],
        ];
    }

    public function test_the_preview_and_sent_document_use_the_fresh_order_not_the_saved_one(): void
    {
        $out = $this->overlay(['seller_full' => self::FRESH], $this->step(self::OLD));

        $this->assertSame(self::FRESH, $out['seller_full']);
        $this->assertSame(self::FRESH, $out['_fill_review_overlay']['seller_full'], 'the authoritative overlay re-applied after expansion is the fresh list too');
    }

    public function test_an_agent_edit_to_the_party_list_is_still_honoured(): void
    {
        $edited = str_replace('Sam Separate', 'Samuel Separate', self::OLD);

        $out = $this->overlay(['seller_full' => self::FRESH], $this->step($edited));

        $this->assertSame($edited, $out['seller_full']);
    }

    public function test_only_party_list_fields_are_refreshed_a_typed_value_in_any_other_field_is_untouched(): void
    {
        $out = $this->overlay(['seller_full' => self::FRESH], $this->step(self::OLD, 'placeholder'));

        $this->assertSame(self::OLD, $out['seller_full'], 'not a derived party list → the typed value stands');
    }

    public function test_step_five_loads_the_fresh_list_into_its_own_form(): void
    {
        $m = new ReflectionMethod(ESignWizardController::class, 'freshenStoredPartyLists');
        $m->setAccessible(true);
        $stepData = ['fill_review' => ['fieldValues' => ['tag-sellers' => self::OLD, 'tag-other' => 'typed']]];
        $fields = [
            ['id' => 'tag-sellers', 'field_name' => 'seller_full', 'type' => 'field_group_display'],
            ['id' => 'tag-other', 'field_name' => 'seller_phone', 'type' => 'placeholder'],
        ];

        $out = $m->invoke(app(ESignWizardController::class), $stepData, $fields, ['seller_full' => self::FRESH, 'seller_phone' => 'x']);

        $this->assertSame(self::FRESH, $out['fill_review']['fieldValues']['tag-sellers']);
        $this->assertSame('typed', $out['fill_review']['fieldValues']['tag-other']);
    }

    public function test_a_flow_without_a_proxy_is_unaffected(): void
    {
        $plain = 'Anne Adams (ID: 1); Ben Brown (ID: 2); and Carl Clark (ID: 3)';
        $out = $this->overlay(['seller_full' => $plain], $this->step($plain));

        $this->assertSame($plain, $out['seller_full']);
    }
}
