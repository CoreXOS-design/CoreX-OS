<?php

namespace App\Services\Rentals;

use App\Models\Docuperfect\Template;

/**
 * .ai/specs/leases.md §15.4 step 4 / §15.8.2 — how an agency's OWN lease agreement prints the values the launcher
 * seeds, so {@see LeaseAgreementDocumentValues} can dress each value for the page without doing it twice.
 *
 * Agreements differ: one prints a blank text box ("Monthly Rental: ____") and needs the whole "R 8,500.00" / "10%";
 * another already carries the unit itself — a currency field whose "R" is drawn by the template (`field-currency`, or
 * a literal "R" just before the box), or "…an escalation of [box]% per annum" with the "%" written after it — and
 * must be handed the bare number or it prints "R R 8,500.00" / "10%%". A date field that is a native date input
 * (type `date`) shows nothing for any value but Y-m-d.
 *
 * Returns, per registry key the agency's map carries (only the keys whose type needs it):
 *   'bare' — the template prints the "R" (money) or the "%" (percent): seed the number alone
 *   'iso'  — the target field is a date input: seed Y-m-d
 * A key not returned is dressed in full. Anything that cannot be read (a PDF-overlay agreement, a template whose
 * source is not on this server) is dressed in full — the plain text box is the safe default.
 */
class LeaseSeedPresentation
{
    /**
     * @param array<string,array<string,mixed>> $map the NORMALISED field map (key => ['field' => name, …])
     * @param array<int|string,array<string,mixed>> $fields the template's fill-in fields as the e-sign wizard lists them
     * @return array<string,string> key => 'bare' | 'iso'
     */
    public function for(?Template $template, array $map, array $fields): array
    {
        $registry = (array) config('lease-agreement-fields.fields', []);
        $source = $this->sourceOf($template);

        $out = [];
        foreach ($map as $key => $entry) {
            $field = (string) ($entry['field'] ?? '');
            $type = (string) ($registry[$key]['type'] ?? '');
            if ($field === '' || ! in_array($type, ['date', 'money', 'percent'], true)) {
                continue;
            }

            if ($type === 'date' && $this->isDateInput($fields, $field)) {
                $out[$key] = 'iso';
            } elseif ($type === 'money' && $source !== null && $this->printsCurrency($source, $field)) {
                $out[$key] = 'bare';
            } elseif ($type === 'percent' && $source !== null && $this->printsPercentSign($source, $field)) {
                $out[$key] = 'bare';
            }
        }

        return $out;
    }

    /** @param array<int|string,array<string,mixed>> $fields */
    private function isDateInput(array $fields, string $fieldName): bool
    {
        $field = app(LeaseWizardFieldResolver::class)->find($fields, $fieldName);

        return $field !== null && strtolower((string) ($field['type'] ?? $field['tag_type'] ?? '')) === 'date';
    }

    /** The "R" is drawn by a `field-currency` class on the box, or written as a literal "R" right before it. */
    private function printsCurrency(string $source, string $field): bool
    {
        $name = preg_quote($field, '/');
        $tag = '<span\b[^>]*\bdata-field="' . $name . '"[^>]*>';

        return preg_match('/<span\b[^>]*\bclass="[^"]*\bfield-currency\b[^"]*"[^>]*\bdata-field="' . $name . '"/i', $source) === 1
            || preg_match('/<span\b[^>]*\bdata-field="' . $name . '"[^>]*\bclass="[^"]*\bfield-currency\b/i', $source) === 1
            || preg_match('/(?<![A-Za-z])R\s*(?:<\/?(?:strong|b|i|em|u)\b[^>]*>\s*)*' . $tag . '/i', $source) === 1;
    }

    /** The "%" is written right after the box: "…escalation of [box]% per annum". */
    private function printsPercentSign(string $source, string $field): bool
    {
        $name = preg_quote($field, '/');

        return preg_match('/<span\b[^>]*\bdata-field="' . $name . '"[^>]*>[^<]*<\/span>(?:\s|<\/?(?:strong|b|i|em|u|span)\b[^>]*>)*%/i', $source) === 1;
    }

    /** The compiled web template's markup (a CDS or Blade web template), or null when there is none to read. */
    private function sourceOf(?Template $template): ?string
    {
        $view = (string) ($template?->blade_view ?? '');
        if ($view === '' || ($template->render_type ?? 'pdf') !== 'web') {
            return null;
        }

        try {
            $path = view()->getFinder()->find($view);

            return is_file($path) ? (string) file_get_contents($path) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
