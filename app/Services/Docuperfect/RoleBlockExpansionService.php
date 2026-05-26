<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\SignatureRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Recipient Loop Engine — B2 expansion / stamping pass.
 *
 * Walks the rendered HTML body, parses each `data-field` attribute to recover
 * its role-base + instance-index, and stamps two new attributes onto the
 * field's opening tag:
 *
 *   data-recipient-identity="{role_base}_{instance_index}"
 *   data-role-token="{role_base}"
 *
 * The identity matches `SignatureRequest::role_identity` (B1 accessor) so the
 * signing-view JS in B3 can filter fields by "is this me" without parsing
 * field names client-side.
 *
 * Orphan handling — when a hardcoded numbered field references an instance
 * index beyond the actual recipient count for that role (e.g. template has
 * `seller_3_phone` but the document only has 2 seller recipients), the field
 * gets `data-orphan-recipient="1"` so downstream code can hide/no-op it
 * without crashing. A structural warning is logged but rendering never
 * blocks (templates may legitimately over-provision fields).
 *
 * Backward compat: templates with one recipient per role stamp `role_1` on
 * every matching field, which is exactly what the single-recipient path
 * already implicitly assumed — no behavioural change for legacy documents.
 */
final class RoleBlockExpansionService
{
    public function __construct(
        private readonly RoleBlockDetectionService $detector,
    ) {}

    /**
     * Stamp identity + role-token attributes onto every `data-field` element
     * in the supplied HTML body.
     *
     * @param  string                            $html           Rendered HTML body (post-letterhead, post-block-render).
     * @param  Collection<int, SignatureRequest> $recipients     All signature_requests for this template (any party_role).
     * @param  int|null                          $templateId     Optional — used only for log context when warnings fire.
     * @return string                                            Rewritten HTML body with identity stamps.
     */
    public function stampIdentities(
        string $html,
        Collection $recipients,
        ?int $templateId = null,
    ): string {
        if ($html === '' || trim($html) === '') {
            return $html;
        }

        // Bucket recipients by canonical role-base key. Wizard tokens
        // (seller, lessor, lessee, landlord, tenant) and canonical tokens
        // (owner_party, acquiring_party) coexist on signature_requests.party_role,
        // so we normalise both into the same lookup map for max-instance
        // resolution.
        $countsByRole = $this->buildRecipientCountsByRole($recipients);

        // Single-pass rewrite: match each opening tag carrying data-field="..."
        // and append the two new attributes (plus the orphan flag when
        // applicable) just before the closing `>`. This avoids running-offset
        // bookkeeping that would be needed with index-based splicing.
        $orphanLog = [];
        $pattern   = '/<([a-zA-Z][a-zA-Z0-9]*)(\s[^>]*?)data-field="([^"]+)"([^>]*)>/i';

        $stamped = preg_replace_callback(
            $pattern,
            function (array $m) use ($countsByRole, &$orphanLog): string {
                [$full, $tag, $preAttrs, $fieldName, $postAttrs] = $m;

                $parsed   = $this->detector->parseFieldName($fieldName);
                $roleBase = $parsed['role_base'];
                $idx      = $parsed['instance_index'];

                if ($roleBase === null) {
                    // Field name doesn't map to any known role base — leave
                    // the tag untouched (singleton metadata fields like
                    // "additional_information" or "purchase_price").
                    return $full;
                }

                $identity        = $roleBase . '_' . $idx;
                $recipientCount  = $countsByRole[$roleBase] ?? 0;
                $isOrphan        = $recipientCount > 0 && $idx > $recipientCount;

                if ($isOrphan) {
                    $orphanLog[] = [
                        'field'    => $fieldName,
                        'role'     => $roleBase,
                        'index'    => $idx,
                        'have'     => $recipientCount,
                    ];
                }

                $extra = sprintf(
                    ' data-recipient-identity="%s" data-role-token="%s"%s',
                    e($identity),
                    e($roleBase),
                    $isOrphan ? ' data-orphan-recipient="1"' : '',
                );

                return '<' . $tag . $preAttrs . 'data-field="' . $fieldName . '"' . $postAttrs . $extra . '>';
            },
            $html,
        );

        if ($stamped === null) {
            // preg_replace_callback returns null on PCRE failure — fall back
            // to the original HTML so signing never blocks on a stamping
            // glitch.
            Log::warning('RoleBlockExpansionService: PCRE failure during stamping', [
                'template_id'    => $templateId,
                'preg_last_error' => preg_last_error(),
            ]);
            return $html;
        }

        if (!empty($orphanLog)) {
            Log::info('RoleBlockExpansionService: orphan recipient fields detected', [
                'template_id' => $templateId,
                'orphans'     => $orphanLog,
            ]);
        }

        return $stamped;
    }

    /**
     * Build a {role_base => count} map from the recipient collection.
     *
     * Wizard's raw role aliases collapse onto their canonical owner_party /
     * acquiring_party twins so a field named with EITHER vocabulary resolves
     * to the same recipient count:
     *
     *   seller / lessor / landlord  → also counted as owner_party
     *   buyer / lessee / tenant     → also counted as acquiring_party
     *
     * This keeps templates authored with the raw wizard tokens
     * (`seller_1_phone`) interoperable with documents whose recipients were
     * stored under the canonical token (`party_role = 'owner_party'`).
     *
     * @param  Collection<int, SignatureRequest> $recipients
     * @return array<string, int>
     */
    private function buildRecipientCountsByRole(Collection $recipients): array
    {
        $counts = [];

        foreach ($recipients as $r) {
            $role = strtolower((string) ($r->party_role ?? ''));
            if ($role === '') {
                continue;
            }
            $counts[$role] = ($counts[$role] ?? 0) + 1;

            // Mirror under the canonical twin so lookups by either token
            // resolve to the same count.
            $twin = $this->canonicalTwin($role);
            if ($twin !== null && $twin !== $role) {
                $counts[$twin] = ($counts[$twin] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Map a wizard raw token to its canonical twin (or vice-versa).
     */
    private function canonicalTwin(string $role): ?string
    {
        return match ($role) {
            'seller', 'lessor', 'landlord' => 'owner_party',
            'buyer', 'lessee', 'tenant'    => 'acquiring_party',
            'owner_party'                  => 'seller',
            'acquiring_party'              => 'buyer',
            default                        => null,
        };
    }
}
