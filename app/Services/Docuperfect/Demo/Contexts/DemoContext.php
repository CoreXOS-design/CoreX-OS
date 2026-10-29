<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Demo\Contexts;

use App\Models\Docuperfect\Template;

/**
 * .ai/specs/esign-template-demo-preview.md §3a — one provider per kind of document. It knows who the parties are, what a believable deal or lease
 * for that kind of document looks like, and which values its merge fields take. Pure code: no stored records, never reads a real contact,
 * property or agency. TemplateDemoSample asks the provider first and falls back to config/template-demo-sample.php for everything else.
 */
interface DemoContext
{
    /** Stable key, shown on the demo page and used by tests (e.g. "sales_mandate"). */
    public function key(): string;

    /** Plain name shown to the person looking at the demo ("Sole or open mandate"). */
    public function label(): string;

    /** The value the signing pages read as document_context: 'sales' or 'rental'. */
    public function documentContext(Template $t): string;

    /** Does this provider describe the template? $slug is the document type's slug (or null), $txn is 'sales' | 'rentals' | null. */
    public function matches(Template $t, ?string $slug, ?string $txn): bool;

    /** @return array<string,array{label:string,name:string}> the sample parties of this kind of document, by role. */
    public function parties(): array;

    /** @return array<string,string> exact merge-field name => sample value (the document's own wording of the field). */
    public function vars(): array;

    /** @return array<string,string> regex => sample value, tried before the global patterns. */
    public function patterns(): array;
}
