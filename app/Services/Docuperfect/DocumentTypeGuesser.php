<?php

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\DocumentType;

/**
 * esign-import-blanks.md - a first guess at what an imported Word document is, from its title and its wording.
 *
 * The importer set no category and no document type, so a fresh import fell to "generic" (wrong demo sample, an unset-type warning
 * in readiness, the wrong signing set-up). This reads the title first (the strongest signal) and then the opening wording, and
 * names the document type and the sales / rentals category. It only ever fills the two builder dropdowns, which stay editable;
 * nothing is saved on the strength of a guess alone.
 */
class DocumentTypeGuesser
{
    /** Title / wording tests, most specific first: [slug, regex over the lower-cased text]. */
    private const RULES = [
        ['marketing_permission', '/marketing permission/'],
        ['disclosure', '/\b(mandatory )?disclosure\b|defect/'],
        ['addendum', '/\baddendum\b|\bannexure\b/'],
        ['notice', '/^\W*(notice|letter of demand)\b|\bnotice of\b/'],
        ['inspection_report', '/\b(inspection|condition report|inventory)\b/'],
        ['fica', '/\bfica\b|know your (client|customer)|natural person questionnaire|schedule 4/'],
        ['mandate', '/\b(letting|rental|sole|open|dual|exclusive) (mandate|authority)\b|authority to (sell|let)|\bmandate\b/'],
        ['offer_to_purchase', '/offer to purchase|\botp\b|deed of sale|agreement of sale|sale agreement|contract of sale/'],
        ['lease_agreement', '/\blease\b|rental agreement|tenancy/'],
        ['other', '/rental application|application to rent|tenant application/'],
    ];

    /** @return array{category:?string,document_type_id:?int,slug:?string,note:string}|null null when nothing is recognisable */
    public function guess(string $title, string $text = ''): ?array
    {
        $title = mb_strtolower(trim($title));
        $head = mb_strtolower(mb_substr($text, 0, 600));

        $slug = null;
        foreach ([$title, $head] as $haystack) {
            if ($haystack === '') {
                continue;
            }
            foreach (self::RULES as [$candidate, $rx]) {
                if (preg_match($rx, $haystack)) {
                    $slug = $candidate;
                    break 2;
                }
            }
        }
        if ($slug === null) {
            return null;
        }

        $category = $this->category($slug, $title . ' ' . $head);
        $id = DocumentType::query()->where('slug', $slug)->value('id');

        return [
            'category' => $category,
            'document_type_id' => $id ? (int) $id : null,
            'slug' => $slug,
            'note' => 'Guessed from the title and wording of the Word file - change it if it is wrong.',
        ];
    }

    private function category(string $slug, string $text): ?string
    {
        if (in_array($slug, ['lease_agreement', 'rental_agreement'], true) || str_contains($text, 'rental application')) {
            return 'rentals';
        }
        if (in_array($slug, ['offer_to_purchase', 'otp', 'sale_agreement'], true)) {
            return 'sales';
        }
        $rental = preg_match_all('/\b(lessor|lessee|landlord|tenant|lease|letting|rental|rent)\b/', $text);
        $sales = preg_match_all('/\b(seller|purchaser|buyer|purchase price|transfer|conveyancer|sale|sell)\b/', $text);
        if ($rental === 0 && $sales === 0) {
            return null;
        }

        return $rental > $sales ? 'rentals' : 'sales';
    }
}
