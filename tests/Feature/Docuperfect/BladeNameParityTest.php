<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Http\Controllers\Docuperfect\ESignWizardController;
use App\Http\Controllers\Docuperfect\TemplateController;
use App\Services\WebTemplateDataService;
use Tests\TestCase;

/**
 * The same box is named in three places: when the template is built (TemplateController), when its data is merged
 * (WebTemplateDataService) and in the e-sign wizard (ESignWizardController). The first two are the document's own
 * `data-field` names; if the wizard names a box differently, what an agent (or the lease launcher) puts in it lands
 * under a name the printed page never reads. Found on QA1 (23 Oct 2026): a lease deposit printed raw "5500.00" because
 * the wizard's copy had no `deposit_amount` and called the box `property_deposit_amount`.
 */
final class BladeNameParityTest extends TestCase
{
    private function derive(object $who, string $type, string $column, ?string $contactType): ?string
    {
        $m = new \ReflectionMethod($who, 'deriveBladeName');
        $m->setAccessible(true);

        return $m->invoke($who, $type, $column, $contactType);
    }

    /** @return array<string, array{0:string,1:string,2:?string}> */
    public static function boxes(): array
    {
        $out = [];
        foreach (['property_number', 'erf_number', 'erf', 'address', 'street', 'suburb', 'township', 'district', 'complex_name',
            'unit_number', 'price', 'rental_amount', 'deposit_amount', 'expiry_date', 'bedrooms', 'description'] as $column) {
            $out["property.$column"] = ['property', $column, null];
        }
        foreach (['Landlord', 'Tenant', 'Seller', 'Buyer', 'Seller 2'] as $role) {
            foreach (['first_name+last_name', 'full_name', 'last_name', 'first_name', 'id_number', 'address', 'phone', 'cell', 'email', 'bank_name', 'bank_account_number'] as $column) {
                $out["contact.$role.$column"] = ['contact', $column, $role];
            }
        }
        $out['computed.total'] = ['computed', 'total', null];
        $out['deal.commission_amount'] = ['deal', 'commission_amount', null];

        return $out;
    }

    /** @dataProvider boxes */
    public function test_the_wizard_names_a_box_exactly_as_the_template_builder_and_the_data_merge_do(string $type, string $column, ?string $contactType): void
    {
        $wizard = $this->derive(app(ESignWizardController::class), $type, $column, $contactType);

        $this->assertSame($this->derive(app(TemplateController::class), $type, $column, $contactType), $wizard, "builder vs wizard for $type.$column");
        $this->assertSame($this->derive(app(WebTemplateDataService::class), $type, $column, $contactType), $wizard, "data merge vs wizard for $type.$column");
    }

    public function test_a_property_deposit_box_is_called_deposit_amount_everywhere(): void
    {
        $this->assertSame('deposit_amount', $this->derive(app(ESignWizardController::class), 'property', 'deposit_amount', null));
    }
}
