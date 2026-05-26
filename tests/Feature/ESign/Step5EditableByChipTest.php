<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Http\Controllers\Docuperfect\ESignWizardController;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Fix A — ESignWizardController::buildFieldsFromMappings() preserves
 * the full `editable_by` array on each entry as `editableBy`. The
 * legacy `assignedTo` single-string contract is preserved (first of
 * array) for the 8+ JS call sites in wizard.blade.php that expect it.
 *
 * Pre-fix, the array was collapsed to its first element at line 3508,
 * so Step 5 chip rendering only ever produced ONE chip per field even
 * when the template defined both seller + agent.
 */
final class Step5EditableByChipTest extends TestCase
{
    private ReflectionMethod $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new ReflectionMethod(ESignWizardController::class, 'buildFieldsFromMappings');
        $this->builder->setAccessible(true);
    }

    public function test_two_role_array_preserves_both_tokens_on_editable_by(): void
    {
        $controller = new ESignWizardController();
        $mappings = [
            ['id' => 'tag1', 'label' => 'Seller Address 1', 'editable_by' => ['owner_party', 'agent'], 'type' => 'placeholder'],
        ];

        $entries = $this->builder->invoke($controller, $mappings, []);

        $this->assertCount(1, $entries);
        $this->assertSame(['owner_party', 'agent'], $entries[0]['editableBy'],
            'editable_by array must pass through intact');
        $this->assertSame('owner_party', $entries[0]['assignedTo'],
            'assignedTo keeps legacy single-string contract = first element');
    }

    public function test_single_string_editable_by_normalises_to_one_element_array(): void
    {
        $controller = new ESignWizardController();
        $mappings = [
            ['id' => 'tag2', 'label' => 'Agent only', 'editable_by' => 'agent', 'type' => 'placeholder'],
        ];

        $entries = $this->builder->invoke($controller, $mappings, []);

        $this->assertSame(['agent'], $entries[0]['editableBy']);
        $this->assertSame('agent', $entries[0]['assignedTo']);
    }

    public function test_missing_editable_by_defaults_to_agent(): void
    {
        $controller = new ESignWizardController();
        $mappings = [
            ['id' => 'tag3', 'label' => 'No editable_by', 'type' => 'placeholder'],
        ];

        $entries = $this->builder->invoke($controller, $mappings, []);

        $this->assertSame(['agent'], $entries[0]['editableBy']);
        $this->assertSame('agent', $entries[0]['assignedTo']);
    }

    public function test_filled_by_key_takes_precedence_over_editable_by(): void
    {
        // The original code accepted both `filled_by` and `editable_by`
        // (filled_by first). The fix preserves that precedence.
        $controller = new ESignWizardController();
        $mappings = [
            ['id' => 'tag4', 'label' => 'Field', 'filled_by' => ['acquiring_party'], 'editable_by' => ['owner_party'], 'type' => 'placeholder'],
        ];

        $entries = $this->builder->invoke($controller, $mappings, []);

        $this->assertSame(['acquiring_party'], $entries[0]['editableBy']);
    }

    public function test_array_with_empty_values_strips_them(): void
    {
        $controller = new ESignWizardController();
        $mappings = [
            ['id' => 'tag5', 'label' => 'Field', 'editable_by' => ['owner_party', '', null, 'agent'], 'type' => 'placeholder'],
        ];

        $entries = $this->builder->invoke($controller, $mappings, []);

        $this->assertSame(['owner_party', 'agent'], $entries[0]['editableBy'],
            'empty / null values are dropped before storing');
    }
}
