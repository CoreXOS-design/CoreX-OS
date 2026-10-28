<?php

namespace App\Services\DealV2;

use Illuminate\Support\Facades\DB;

/**
 * Who a document filed from a deal is linked to: the deal's OWN parties (`deal_contacts`, role
 * seller / buyer), on the side the document belongs to. Never the property's wider contact list —
 * a lead or a second buyer linked to the property is not a party to this deal and must not be
 * handed its offer, its FICAs or its attorney correspondence.
 *
 * Shared by the filing path (DealDocumentService) and the clean-up command
 * (deal-docs:fix-contact-links) so both apply one rule. The type-to-side map is config
 * (config/deal-document-parties.php), not code.
 */
class DealDocumentPartyResolver
{
    public const SELLER = 'seller';
    public const BUYER  = 'buyer';
    public const BOTH   = 'both';

    /** Which side a document belongs to: seller, buyer or both. */
    public function sideFor(?string $typeSlug, ?string $fileName, ?string $hint = null): string
    {
        if (in_array($hint, [self::SELLER, self::BUYER, self::BOTH], true)) {
            return $hint;
        }

        $slug = strtolower(trim((string) $typeSlug));
        if ($slug !== '') {
            if (in_array($slug, (array) config('deal-document-parties.seller_slugs', []), true)) {
                return self::SELLER;
            }
            if (in_array($slug, (array) config('deal-document-parties.buyer_slugs', []), true)) {
                return self::BUYER;
            }
        }

        $name   = (string) $fileName;
        $seller = $this->mentions($name, (array) config('deal-document-parties.seller_words', []));
        $buyer  = $this->mentions($name, (array) config('deal-document-parties.buyer_words', []));
        if ($seller && ! $buyer) {
            return self::SELLER;
        }
        if ($buyer && ! $seller) {
            return self::BUYER;
        }

        return self::BOTH;
    }

    /**
     * The deal's own parties as contact_id => 'seller'|'buyer'. A contact recorded on both sides
     * keeps the seller role.
     *
     * @return array<int, string>
     */
    public function parties(int $dealId): array
    {
        $out = [];
        foreach (DB::table('deal_contacts')->where('deal_id', $dealId)->whereIn('role', [self::SELLER, self::BUYER])
            ->orderByRaw("role = 'seller' desc")->get(['contact_id', 'role']) as $row) {
            $out[(int) $row->contact_id] ??= $row->role;
        }

        return $out;
    }

    /**
     * The deal parties a document on $side is linked to, as contact_id => 'seller'|'buyer'.
     *
     * @return array<int, string>
     */
    public function targets(int $dealId, string $side): array
    {
        $parties = $this->parties($dealId);
        if ($side === self::BOTH) {
            return $parties;
        }

        return array_filter($parties, fn (string $role) => $role === $side);
    }

    private function mentions(string $name, array $words): bool
    {
        if ($name === '' || $words === []) {
            return false;
        }
        $alt = implode('|', array_map(fn ($w) => preg_quote((string) $w, '/'), $words));

        return (bool) preg_match('/\b(?:' . $alt . ')s?\b/i', $name);
    }
}
