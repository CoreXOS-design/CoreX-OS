<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Demo\Contexts;

use App\Models\Docuperfect\Template;

/** Picks the provider for a template: the document type first, then the sales/rental category, then the words in the name. First match wins; GenericContext always matches. */
final class DemoContextResolver
{
    /** Order matters: the narrower kinds come before the wider ones. */
    public const PROVIDERS = [
        RentalApplicationContext::class,
        NoticeContext::class,
        InspectionContext::class,
        FicaContext::class,
        SaleAgreementContext::class,
        RentalDisclosureContext::class,
        SalesDisclosureContext::class,
        RentalMandateContext::class,
        SalesMandateContext::class,
        SalesAddendumContext::class,
        LeaseAgreementContext::class,
        GenericContext::class,
    ];

    public function for(Template $t): DemoContext
    {
        $slug = null;
        try {
            $slug = $t->documentType?->slug;
        } catch (\Throwable) {
            // no readable document type: the category and the name decide
        }
        $txn = $t->resolvedTransactionCategory();
        foreach (self::PROVIDERS as $class) {
            /** @var DemoContext $p */
            $p = new $class();
            if ($p->matches($t, $slug ? (string) $slug : null, $txn)) {
                return $p;
            }
        }

        return new GenericContext();
    }

    /** @return list<DemoContext> every provider, for the tests and the specs' table. */
    public static function all(): array
    {
        return array_map(fn (string $c) => new $c(), self::PROVIDERS);
    }
}
