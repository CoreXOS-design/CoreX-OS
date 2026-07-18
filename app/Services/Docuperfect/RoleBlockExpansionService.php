<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use App\Models\Contact;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\Template;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Recipient Loop Engine — B2 + B2.5 expansion pass.
 *
 * Two entry points:
 *
 *   stampIdentities()    — B2 path. Regex-based single-pass stamping
 *                          for the simple/legacy case (no DOM rewrite).
 *
 *   expandWithLooping()  — B2.5 path. DOMDocument-based pipeline that
 *                          stamps identities AND, where the template uses a
 *                          single-block authoring style with multiple
 *                          recipients, duplicates the block N times and
 *                          pre-fills each clone from its recipient's contact.
 *
 * Four cases drive `expandWithLooping`'s behaviour per role-block:
 *
 *   A. Single-block, N recipients (N>1): duplicate the block's LCA N times,
 *      stamp each clone with role_n identity, mangle data-field for DOM
 *      uniqueness (suffix `__r{n}`), prepend a section header, pre-fill
 *      fields from recipient n's contact.
 *   B. Multi-block hardcoded, K instance indexes == N recipients: stamp
 *      existing fields in place (no duplication). Today's HFC templates
 *      (e.g. template 111 with seller_1_phone..seller_4_phone) hit this.
 *   C. Single-block, 1 recipient: stamp the single block; no header index.
 *   D. Mismatched K hardcoded vs N recipients:
 *        D.1  K > N: stamp first N, mark instances N+1..K orphan.
 *        D.2  K < N (with K>1): stamp K, clone the idx=K sub-block
 *             (if isolable) for instances K+1..N. If the idx=K sub-block
 *             cannot be isolated (no per-instance container in template),
 *             log a structural warning and stop after stamping K.
 *
 * Per-instance DOM uniqueness: cloned fields rename their `data-field`
 * attribute to `{original}__r{n}` so multiple identical names never collide
 * in the DOM. Downstream code reads `data-recipient-identity` as the
 * authoritative identity; `data-field`'s `__r{n}` suffix is purely a DOM
 * uniqueness device.
 *
 * Per-instance pre-fill: when a clone is generated, each field span's text
 * content is overwritten using the recipient's contact record. The mapping
 * is sub-name → contact column:
 *
 *   first_name / last_name        → contact->first_name / ->last_name
 *   name / full_name              → "{first_name} {last_name}" concat
 *   name_surname_id               → "{first_name} {last_name} (ID: {id_number})"
 *   id / id_number                → contact->id_number
 *   email                         → contact->email
 *   phone / cell_phone / mobile   → contact->phone
 *   address / address_1
 *     / address_line_1            → contact->address
 *
 * Unmapped sub-names leave the cloned span's text untouched (i.e. inherit
 * whatever the original WebTemplateDataService merge produced).
 *
 * Stamping-only legacy path (stampIdentities) is preserved for templates
 * that don't need DOM rewriting and for unit tests that validate the
 * regex-level behaviour independent of DOMDocument.
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

    /**
     * AT-291 ITEM 6 — collapse every vocabulary a party can be stamped under
     * (raw wizard token OR canonical token) to ONE stable canonical party
     * key, so `seller`/`lessor`/`landlord`/`owner_party` are recognised as
     * the same party (and likewise the acquiring side). Used to detect
     * same-party role-block nesting.
     */
    private function canonicalParty(string $role): string
    {
        return match ($role) {
            'seller', 'lessor', 'landlord', 'owner_party'      => 'owner_party',
            'buyer', 'lessee', 'tenant', 'acquiring_party'     => 'acquiring_party',
            default                                            => $role,
        };
    }

    /**
     * AT-291 ITEM 6 — true when $block sits inside another `[data-role-block]`
     * element that resolves to the SAME canonical party. Such a nested block
     * is a mixed-vocabulary duplicate: its content is already emitted when the
     * ancestor block is cloned per recipient, so expanding it independently
     * would render the party a second time.
     */
    private function hasSamePartyRoleBlockAncestor(DOMElement $block, string $role): bool
    {
        $canonical = $this->canonicalParty($role);
        $parent = $block->parentNode;
        while ($parent instanceof DOMElement) {
            if ($parent->hasAttribute('data-role-block')) {
                $ancestorRole = strtolower($parent->getAttribute('data-role-block'));
                if ($ancestorRole !== '' && $this->canonicalParty($ancestorRole) === $canonical) {
                    return true;
                }
            }
            $parent = $parent->parentNode;
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────────────
    // B2.5 — DOM-based loop expansion
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Full B2.5 pipeline: detect role-blocks, decide per-block case, apply
     * stamping (and duplication + pre-fill for the single-block path).
     *
     * @param  Template|null                     $template    Optional — used for category resolution + log context.
     * @param  string                            $html        Rendered HTML body (post-letterhead, post-block-render).
     * @param  Collection<int, SignatureRequest> $recipients  All signature_requests for this template.
     * @return string                                         Rewritten HTML body.
     */
    public function expandWithLooping(
        ?Template $template,
        string $html,
        Collection $recipients,
        ?SignatureRequest $currentViewer = null,
        array $fieldMappings = [],
    ): string {
        if (trim($html) === '') {
            return $html;
        }

        $dom = $this->detector->loadFragment($html);
        if ($dom === null) {
            Log::warning('RoleBlockExpansionService: DOM parse failure, falling back to stamping', [
                'template_id' => $template?->id,
            ]);
            return $this->stampIdentities($html, $recipients, $template?->id);
        }
        $xpath = new DOMXPath($dom);
        $recipsByRole   = $this->groupRecipientsByRole($recipients);
        $isSales        = $template?->isSalesDocument() ?? true;
        $structuralLog  = [];

        // CONTRACT-DRIVEN PATH (primary). Find every `[data-role-block]`
        // element — these are the import-time-normalised structural
        // anchors. Group by role, clone each per recipient. No
        // clustering, no LCA-walking, no per-document patching.
        //
        // Templates normalised via cdsGenerate (every save going
        // forward) OR via `php artisan docuperfect:normalize-templates`
        // (one-time backfill) carry the contract. Templates without
        // the contract fall back to the legacy clustering path below
        // with a structured warning so they're visible in logs until
        // the agent runs the backfill.
        $roleBlocks = $xpath->query('//*[@data-role-block]');
        $hasContract = ($roleBlocks !== false && $roleBlocks->length > 0);

        if ($hasContract) {
            $blocksByRole = [];
            foreach ($roleBlocks as $block) {
                if (!$block instanceof DOMElement) {
                    continue;
                }
                $role = strtolower($block->getAttribute('data-role-block'));
                if ($role === '') {
                    continue;
                }
                // AT-291 ITEM 6 — skip a role-block nested INSIDE another
                // role-block of the SAME canonical party. Mixed-vocabulary
                // stamping (a `seller` block nested in an `owner_party` block,
                // or vice-versa — both map to the same party) otherwise clones
                // the inner content once WITH its parent AND again on its own
                // pass, so the seller renders twice. Same-party nesting is
                // always a duplicate; different-party nesting is left intact.
                if ($this->hasSamePartyRoleBlockAncestor($block, $role)) {
                    $structuralLog[] = [
                        'role' => $role,
                        'case' => 'nested-same-party-duplicate-skipped',
                    ];
                    continue;
                }
                $blocksByRole[$role] ??= [];
                $blocksByRole[$role][] = $block;
            }
            $this->expandViaContract(
                $dom,
                $blocksByRole,
                $recipsByRole,
                $isSales,
                $structuralLog,
            );
        } else {
            // Legacy fallback — templates that pre-date the contract.
            // Fires until the agent runs the one-time backfill command.
            // The cluster/LCA logic remains for backward compat; the
            // log entry makes it visible which templates still need
            // normalisation.
            Log::info('RoleBlockExpansionService: rendering unnormalised template via legacy clustering', [
                'template_id' => $template?->id,
                'hint'        => 'run `php artisan docuperfect:normalize-templates --id=' . ($template?->id ?? '?') . '` to migrate this template to the data-role-block contract',
            ]);

            $boundaries     = $this->detectBoundariesOnDom($dom);
            $canonicalOrdinalByRole = $this->resolveCanonicalClusterPerRole($boundaries);
            foreach ($boundaries as $boundary) {
                $this->applyBoundary(
                    $dom,
                    $xpath,
                    $boundary,
                    $recipsByRole,
                    $isSales,
                    $structuralLog,
                    $canonicalOrdinalByRole,
                );
            }
        }

        // B3 — stamp data-viewer-editable on every field the current viewer
        // is authorised to edit. The signing-view JS reads this attribute
        // (not a name-list lookup) so per-recipient scope works across the
        // mangled __r{n} data-field names introduced by Case A duplication.
        if ($currentViewer !== null) {
            $this->stampViewerEditability(
                $dom,
                $currentViewer,
                $this->buildFieldMappingsLookup($fieldMappings),
            );
        }

        if (!empty($structuralLog)) {
            Log::info('RoleBlockExpansionService: structural notes during expansion', [
                'template_id' => $template?->id,
                'notes'       => $structuralLog,
            ]);
        }

        return $this->detector->serializeFragment($dom);
    }

    /**
     * Contract-driven expansion.
     *
     * For each role, group its `[data-role-block]` elements by adjacency
     * (same parent + adjacent siblings = one segment-group sharing one
     * header per recipient). Then for each segment-group, clone every
     * block in the group per recipient as a sequence. The FIRST block
     * in each recipient's sequence gets the prepended "Seller - Name"
     * sub-heading; subsequent blocks in the same group inherit the
     * identity stamps but don't print their own header.
     *
     * Process groups in REVERSE document order so earlier-group
     * mutations don't shift later groups' DOM positions.
     *
     * @param  array<string, list<DOMElement>>                $blocksByRole
     * @param  array<string, Collection<int, SignatureRequest>> $recipsByRole
     * @param  list<array<string, mixed>>                     $structuralLog
     */
    private function expandViaContract(
        DOMDocument $dom,
        array $blocksByRole,
        array $recipsByRole,
        bool $isSales,
        array &$structuralLog,
    ): void {
        foreach ($blocksByRole as $role => $blocks) {
            $recipients = $recipsByRole[$role] ?? collect();
            if ($recipients->isEmpty()) {
                continue;
            }
            $n = $recipients->count();

            // Group adjacent same-parent blocks so segment headers share
            // a single "Seller - Name" per recipient at the top.
            $groups = $this->groupAdjacentRoleBlocks($blocks);

            foreach (array_reverse($groups) as $group) {
                if (count($group) === 1) {
                    // Single-block group — clone-per-recipient with a
                    // header on each clone (mutateCloneForInstance with
                    // prependHeader=true by default).
                    $this->duplicateBlockForRecipients(
                        $dom,
                        $group[0],
                        $role,
                        $recipients,
                        $isSales,
                        $n,
                    );
                } else {
                    // Multi-block group — segments share one header.
                    $this->duplicateUnitGroupForRecipients(
                        $dom,
                        $group,
                        $role,
                        $recipients,
                        $isSales,
                        $n,
                    );
                }
            }
            $structuralLog[] = [
                'role'      => $role,
                'case'      => 'contract',
                'blocks'    => count($blocks),
                'groups'    => count($groups),
                'recipients'=> $n,
            ];
        }
    }

    /**
     * Group `[data-role-block]` elements that share a parent AND are
     * adjacent in document order (only text nodes allowed between them).
     * Adjacent same-role blocks form one segment-group sharing one
     * "Seller - Name" sub-heading per recipient.
     *
     * @param  list<DOMElement> $blocks  in document order
     * @return list<list<DOMElement>>
     */
    private function groupAdjacentRoleBlocks(array $blocks): array
    {
        if (empty($blocks)) {
            return [];
        }
        $groups = [];
        $current = [];
        $prev = null;
        foreach ($blocks as $b) {
            if ($prev === null) {
                $current = [$b];
                $prev = $b;
                continue;
            }
            $adjacent = ($b->parentNode === $prev->parentNode)
                && $this->roleBlockSiblingsAreAdjacent($prev, $b);
            if ($adjacent) {
                $current[] = $b;
            } else {
                $groups[] = $current;
                $current = [$b];
            }
            $prev = $b;
        }
        if (!empty($current)) {
            $groups[] = $current;
        }
        return $groups;
    }

    /**
     * Two role-block siblings count as "adjacent" when only text nodes
     * sit between them in the DOM. Any intervening element-type sibling
     * (a paragraph, heading, etc.) breaks the group — those blocks
     * belong to different segment-groups.
     */
    private function roleBlockSiblingsAreAdjacent(DOMElement $a, DOMElement $b): bool
    {
        $cur = $a->nextSibling;
        while ($cur !== null) {
            if ($cur === $b) {
                return true;
            }
            if ($cur instanceof DOMElement) {
                return false;
            }
            $cur = $cur->nextSibling;
        }
        return false;
    }

    /**
     * Compute the snake_case identity for a given role+index. Mirrors
     * SignatureRequest::role_identity (B1) so server stamping and client
     * checks always agree.
     */
    public static function identityFor(string $roleToken, int $index): string
    {
        return strtolower($roleToken) . '_' . max(1, $index);
    }

    /**
     * Build a map of original-field-name → editable_by[] for fast lookup
     * during viewer-editability stamping. Field-mappings JSON is keyed
     * by tag-ID with snake_case labels — derive the field name (matching
     * what `WebTemplateDataService` injects into the rendered body) by
     * converting the human label to snake_case.
     *
     * @param  array<string, array{label?: string, editable_by?: list<string>, field_name?: string}> $fieldMappings
     * @return array<string, list<string>>  field_name → editable_by[]
     */
    private function buildFieldMappingsLookup(array $fieldMappings): array
    {
        $out = [];
        foreach ($fieldMappings as $mapping) {
            if (!is_array($mapping)) {
                continue;
            }
            $editableBy = $mapping['editable_by'] ?? [];
            if (!is_array($editableBy)) {
                continue;
            }
            // Prefer explicit field_name, fall back to derived from label.
            $name = $mapping['field_name'] ?? null;
            if (!is_string($name) || $name === '') {
                $label = (string) ($mapping['label'] ?? '');
                if ($label === '') {
                    continue;
                }
                $name = strtolower(trim($label));
                $name = preg_replace('/[^a-z0-9]+/', '_', $name);
                $name = trim((string) $name, '_');
            }
            if (!is_string($name) || $name === '') {
                continue;
            }
            $out[$name] = $editableBy;
        }
        return $out;
    }

    /**
     * Walk every `data-field` element and stamp `data-viewer-editable="1"`
     * when the current viewer is authorised to edit it.
     *
     * Authorisation rule:
     *   - Agent path: viewer's party_role === 'agent' AND field's
     *     editable_by contains 'agent' (or 'all').
     *   - Recipient path: viewer's role_identity matches the field's
     *     data-recipient-identity AND the canonical role-token of the
     *     viewer is present in editable_by.
     *
     * Field-mappings lookup falls back to a wide-open editable_by (any
     * party) when the field name isn't found — backward-compatible with
     * templates that don't ship field_mappings (legacy PDF templates).
     *
     * @param  array<string, list<string>> $editableByByField
     */
    private function stampViewerEditability(
        DOMDocument $dom,
        SignatureRequest $viewer,
        array $editableByByField,
    ): void {
        $xpath = new DOMXPath($dom);
        $fields = $xpath->query('//*[@data-field] | //*[@data-field-name]');   // both shapes (CDS writes data-field-name)
        if ($fields === false) {
            return;
        }

        $viewerRole     = strtolower((string) ($viewer->party_role ?? ''));
        $viewerIdentity = strtolower((string) ($viewer->role_identity ?? ''));
        $isAgent        = $viewerRole === 'agent';
        $editableByRole = self::CANONICAL_FOR_VIEWER[$viewerRole] ?? $viewerRole;

        foreach ($fields as $f) {
            if (!$f instanceof DOMElement) {
                continue;
            }
            $fieldName     = $f->getAttribute('data-field');
            $originalField = $f->getAttribute('data-original-field') ?: $fieldName;
            // Strip the __r{n} DOM-uniqueness suffix to recover the
            // logical field name for the mapping lookup.
            $logicalName = preg_replace('/__r\d+$/', '', $originalField);
            $editableBy  = $editableByByField[$logicalName] ?? null;

            // Treat missing mapping as "any party can edit" (legacy
            // behaviour) — the per-instance identity match below still
            // restricts cross-recipient editing.
            $allowedRoles = $editableBy === null
                ? ['all', $editableByRole, 'agent']
                : $editableBy;

            $allowsAll = in_array('all', $allowedRoles, true);

            if ($isAgent && ($allowsAll || in_array('agent', $allowedRoles, true))) {
                $f->setAttribute('data-viewer-editable', '1');
                continue;
            }

            $fieldIdentity = strtolower($f->getAttribute('data-recipient-identity'));
            if ($fieldIdentity === '' || $viewerIdentity === '') {
                continue;
            }
            $roleCanEdit = $allowsAll || in_array($editableByRole, $allowedRoles, true);
            if ($fieldIdentity === $viewerIdentity && $roleCanEdit) {
                $f->setAttribute('data-viewer-editable', '1');
            }
        }
    }

    /**
     * Wizard's raw role tokens map to the canonical editable_by tokens
     * used in field_mappings. Mirrors the same chain SigningController's
     * getEditableFieldsFromMappings() uses, kept here so the rendering
     * pipeline can compute editability without controller coupling.
     */
    private const CANONICAL_FOR_VIEWER = [
        'landlord'   => 'owner_party',
        'lessor'     => 'owner_party',
        'seller'     => 'owner_party',
        'tenant'     => 'acquiring_party',
        'lessee'     => 'acquiring_party',
        'buyer'      => 'acquiring_party',
        'agent'      => 'agent',
        'witness'    => 'witness',
    ];

    /**
     * Re-run detection against an existing DOMDocument (so we share node
     * references with the mutation pass). Returns the same shape as
     * RoleBlockDetectionService::detectBlockBoundaries().
     */
    private function detectBoundariesOnDom(DOMDocument $dom): Collection
    {
        // The detector exposes detectBlockBoundaries(Template, string), which
        // re-loads the HTML. Here we want to share the in-memory DOM, so
        // serialise → re-load isn't needed — we just call the same logic
        // with a no-op fragment-load by re-using the document. Cheaper:
        // serialise once and pass through (DOMDocument re-parse is fast for
        // bodies under ~500KB).
        return $this->detector->detectBlockBoundaries(null, $this->detector->serializeFragment($dom));
    }

    /**
     * Apply the appropriate per-boundary case (A/B/C/D) to the DOM in place.
     *
     * @param  array<string, mixed>                $boundary
     * @param  array<string, Collection<int, SignatureRequest>> $recipsByRole
     * @param  list<array<string, mixed>>          $structuralLog
     */
    private function applyBoundary(
        DOMDocument $dom,
        DOMXPath $xpath,
        array $boundary,
        array $recipsByRole,
        bool $isSales,
        array &$structuralLog,
        array $canonicalOrdinalByRole = [],
    ): void {
        $role            = $boundary['role_token'];
        $maxIdx          = $boundary['max_instance_index'];
        $instanceGroups  = $boundary['instance_groups'];
        $blockNode       = $boundary['block_node'];
        $blockXpath      = $boundary['block_xpath'];
        $totalClusters   = $boundary['total_clusters_for_role'];
        $clusterOrdinal  = $boundary['cluster_ordinal'];
        $isCanonical     = ($canonicalOrdinalByRole[$role] ?? 0) === $clusterOrdinal;

        // Re-resolve block_node against THIS dom (the boundary may have
        // come from a serialise→reload roundtrip, so its DOMElement refs
        // are dead. The xpath fragment is the stable handle.)
        $blockNodeLive = null;
        if ($blockXpath !== null) {
            $found = $xpath->query($blockXpath);
            if ($found !== false && $found->length > 0 && $found->item(0) instanceof DOMElement) {
                $blockNodeLive = $found->item(0);
            }
        }
        // Live field nodes per instance, also via xpath re-resolve.
        $liveInstanceGroups = $this->rehydrateInstanceGroups($dom, $instanceGroups, $role);

        $recipients = $recipsByRole[$role] ?? collect();
        $recipientCount = $recipients->count();

        // Case C: no recipients OR single-block + single-recipient → just stamp.
        if ($recipientCount === 0) {
            // No recipients for this role — every field is orphan.
            foreach ($liveInstanceGroups as $idx => $fields) {
                foreach ($fields as $f) {
                    $this->stampFieldNode($f['node'], $role, $idx, isOrphan: true);
                }
            }
            $structuralLog[] = ['role' => $role, 'case' => 'no-recipients', 'fields' => array_sum(array_map('count', $liveInstanceGroups))];
            return;
        }

        // Single-block authoring: max idx in cluster == 1.
        if ($maxIdx === 1) {
            // Case A or C — every cluster of a multi-recipient role
            // belongs to the recipient and must duplicate per recipient.
            //
            // Earlier implementations (largest-cluster-wins / canonical-
            // ordinal) tried to pick ONE cluster as the "real" block to
            // loop and stamp the others as role_1. That broke template
            // 111's live shape: opening paragraph reference + main
            // seller block both carry seller fields that BELONG to the
            // recipient. The opening paragraph isn't a "stray" — it's
            // where the recipient's name+ID appear; the main block is
            // where their address+phone+email appear. Stamping the
            // main block as role_1-only meant seller_2's address had
            // nowhere to render.
            //
            // The corrected rule: when a role has N>1 recipients, every
            // cluster of that role duplicates N times. The cluster's
            // own LCA is the duplication unit — multi-cluster shape
            // produces multiple independent loops, one per cluster.
            //
            // Single-cluster shape still works the same: one cluster,
            // duplicated N times. Single-recipient shape still works
            // the same: every cluster stamped once with role_1.
            //
            // Future architectural question: a template that legitimately
            // uses the SAME role for multiple unrelated purposes (e.g.
            // an agent block + an agent-only-witness block both flagged
            // editable_by=agent) would over-duplicate under this rule.
            // No such template exists in production today; if it ships
            // the author can mark the secondary cluster with an
            // explicit data-role-block-pinned="1" attribute to opt
            // out of duplication. Tracked as a follow-up if it becomes
            // a real problem.
            if ($recipientCount === 1) {
                // Case C — stamp the existing block with role_1.
                foreach ($liveInstanceGroups[1] ?? [] as $f) {
                    $this->stampFieldNode($f['node'], $role, 1, isOrphan: false);
                }
                return;
            }

            // Case A — duplicate per-block-unit, NOT per-cluster.
            //
            // Decompose the cluster's fields into block units by walking
            // each field up to its nearest block-level ancestor (`<div>`,
            // `<p>`, `<li>`, `<tr>`, `<td>` etc.). Fields sharing a block
            // ancestor form one unit; different block ancestors form
            // separate units. Each unit duplicates N times.
            //
            // Why: template 111's main seller block lays the fields out
            // as sibling `<div class="corex-clause">` lines under the
            // page wrapper — one line for address, another for phone +
            // email. The whole-cluster LCA of those fields walks up to
            // `<div class="corex-page">` (the page wrapper, 19+ KB), so
            // duplicating "the cluster" duplicates the entire page —
            // catastrophic. Block-unit decomposition keeps duplication
            // tight to the actual line containers the agent authored.
            $clusterFields = $liveInstanceGroups[1] ?? [];
            $blockUnits = $this->decomposeFieldsIntoBlockUnits($clusterFields);
            if (empty($blockUnits)) {
                foreach ($clusterFields as $f) {
                    $this->stampFieldNode($f['node'], $role, 1, isOrphan: false);
                }
                $structuralLog[] = [
                    'role'   => $role,
                    'case'   => 'A-no-block-units',
                    'reason' => 'No block-level ancestors found for fields; auto-loop skipped.',
                    'recipients' => $recipientCount,
                ];
                return;
            }

            // INLINE-LIST path — when the cluster has exactly ONE block-unit
            // AND multiple fields, the cluster is a prose sentence wrapping
            // field placeholders (e.g. opening paragraph "I/We, [first]
            // [last] [id], hereby grant..."). The sentence itself MUST NOT
            // duplicate per recipient; instead the field-spans get duplicated
            // INLINE, joined by " and " between recipients, so the prose
            // reads: "I/We, James VDM 3112 and Steve Jobs 6789, hereby
            // grant...". This is the contract for opening-paragraph
            // references in legal mandate templates — the parties are
            // listed inline, the main data block below is where each
            // recipient gets their own labelled section.
            if (count($blockUnits) === 1 && count($clusterFields) > 1) {
                $this->inlineListClusterForRecipients(
                    $dom,
                    $clusterFields,
                    $role,
                    $recipients,
                    $recipientCount,
                );
                return;
            }
            // Group consecutive block-units that share a parent into
            // ONE recipient-instance group so the "Seller N: Name"
            // sub-heading prints once per recipient at the top of the
            // group, with all the group's lines rendered underneath —
            // not once per block-unit.
            //
            // Template 111 example: cluster 1's two block units
            // (address line + phone+email line) are sibling
            // `<div class="corex-clause">`s under `<div class="corex-page">`.
            // Pre-fix output:
            //   Seller 1: James  /  address line  /
            //   Seller 2: Steve  /  address line  /
            //   Seller 1: James  /  phone+email line  /
            //   Seller 2: Steve  /  phone+email line
            // Post-fix output:
            //   Seller 1: James  /  address line  /  phone+email line  /
            //   Seller 2: Steve  /  address line  /  phone+email line
            //
            // Block units with different parents fall into separate
            // groups (each a single-unit group) — falls back to the
            // existing per-unit duplication shape for those, no
            // regression for templates that don't share parents.
            $unitGroups = $this->groupConsecutiveBlockUnits($blockUnits);
            // Iterate groups in REVERSE document order so duplicating
            // earlier groups doesn't shift later groups' DOM positions.
            foreach (array_reverse($unitGroups) as $group) {
                if (count($group) === 1) {
                    // Single-unit group — existing duplication path
                    // (one header per clone, one clone per recipient).
                    $this->duplicateBlockForRecipients(
                        $dom,
                        $group[0],
                        $role,
                        $recipients,
                        $isSales,
                        $recipientCount,
                    );
                } else {
                    $this->duplicateUnitGroupForRecipients(
                        $dom,
                        $group,
                        $role,
                        $recipients,
                        $isSales,
                        $recipientCount,
                    );
                }
            }
            return;
        }

        // max_idx > 1 → hardcoded multi-instance template (Case B or D).
        $K = $maxIdx;
        $N = $recipientCount;

        // Stamp every existing instance in place. Orphan-mark instances
        // whose idx exceeds N (Case D.1 = K > N).
        foreach ($liveInstanceGroups as $idx => $fields) {
            $isOrphan = ($idx > $N);
            foreach ($fields as $f) {
                $this->stampFieldNode($f['node'], $role, $idx, isOrphan: $isOrphan);
            }
        }

        if ($K === $N) {
            // Case B — fully matched, nothing more to do.
            return;
        }
        if ($K > $N) {
            // Case D.1 — already stamped + orphan-marked above. Log it.
            $orphans = 0;
            for ($i = $N + 1; $i <= $K; $i++) {
                $orphans += count($liveInstanceGroups[$i] ?? []);
            }
            $structuralLog[] = [
                'role' => $role, 'case' => 'D.1-overprovision',
                'hardcoded' => $K, 'recipients' => $N, 'orphan_fields' => $orphans,
            ];
            return;
        }

        // Case D.2 — K < N: template has fewer hardcoded blocks than
        // recipients. Try to find the per-instance subtree for idx=K and
        // duplicate it (N-K) times for the missing recipients.
        $kSubTree = $this->findInstanceSubtree($dom, $liveInstanceGroups, $K, $role);
        if ($kSubTree === null) {
            $structuralLog[] = [
                'role' => $role, 'case' => 'D.2-no-instance-subtree',
                'reason' => 'Template has hardcoded ' . $K . ' instance(s) but ' . $N . ' recipients; could not isolate idx=' . $K . ' subtree for duplication. Template author should move to single-block style or add more hardcoded instances.',
                'hardcoded' => $K, 'recipients' => $N,
            ];
            return;
        }
        $structuralLog[] = [
            'role' => $role, 'case' => 'D.2-auto-fill',
            'hardcoded' => $K, 'recipients' => $N, 'duplicated' => $N - $K,
        ];
        $this->duplicateSubtreeForIndices(
            $dom,
            $kSubTree,
            $role,
            $recipients,
            $isSales,
            fromIndex: $K + 1,
            toIndex: $N,
            totalInstances: $N,
        );
    }

    /**
     * Decompose a cluster's fields into block-level duplication units.
     *
     * For each field, walk up to find the nearest "block" ancestor — a
     * `<div>`, `<p>`, `<li>`, `<tr>`, `<td>`, `<section>`, `<article>`,
     * `<aside>` or `<header>`/`<footer>`. Fields sharing the same block
     * ancestor form one unit (e.g. phone + email rendered side-by-side
     * in the same `<div class="corex-clause">`). Fields in different
     * block ancestors form separate units (e.g. address in one line-div,
     * phone in another). Each unit is a duplication target; the caller
     * clones the unit N times and stamps each clone with a recipient
     * identity.
     *
     * Returned units are in document order. Duplicate them in REVERSE
     * to avoid shifting later units' positions during mutation.
     *
     * @param  list<array{field_name:string,sub_name:?string,node:DOMElement}> $fields
     * @return list<DOMElement>
     */
    private function decomposeFieldsIntoBlockUnits(array $fields): array
    {
        $blockTags = ['div', 'p', 'li', 'tr', 'td', 'section', 'article', 'aside', 'header', 'footer'];
        $seen = [];
        $units = [];
        foreach ($fields as $f) {
            $node = $f['node'] ?? null;
            if (!$node instanceof DOMElement) {
                continue;
            }
            $blockAncestor = $this->findBlockAncestor($node, $blockTags);
            if ($blockAncestor === null) {
                continue;
            }
            $hash = spl_object_hash($blockAncestor);
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $units[] = $blockAncestor;
        }
        return $units;
    }

    /**
     * Group consecutive block-units that share the same DOM parent into
     * a single recipient-instance group. Within a group, ONE
     * "Seller N: Name" sub-heading prints once at the top per recipient;
     * all the group's lines render underneath that heading.
     *
     * Units with different parents fall into separate single-unit
     * groups — preserves the existing per-unit duplication shape for
     * templates that don't share parents.
     *
     * The detector already returns units in document order, so this
     * pass is a simple linear walk: same parent as previous → extend
     * current group; different parent → start a new group.
     *
     * @param  list<DOMElement> $units
     * @return list<list<DOMElement>>  groups in document order
     */
    private function groupConsecutiveBlockUnits(array $units): array
    {
        if (empty($units)) {
            return [];
        }
        $groups = [];
        $currentGroup = [];
        $currentParent = null;
        foreach ($units as $unit) {
            $parent = $unit->parentNode;
            if ($currentParent === null) {
                $currentParent = $parent;
                $currentGroup = [$unit];
                continue;
            }
            if ($parent === $currentParent) {
                $currentGroup[] = $unit;
            } else {
                $groups[] = $currentGroup;
                $currentGroup = [$unit];
                $currentParent = $parent;
            }
        }
        if (!empty($currentGroup)) {
            $groups[] = $currentGroup;
        }
        return $groups;
    }

    /**
     * Walk a node's parent chain until we hit a block-level element.
     * Stops at <body> / the wrapper root — returns null when no block
     * ancestor exists short of the document root.
     *
     * @param  list<string> $blockTags
     */
    private function findBlockAncestor(DOMElement $node, array $blockTags): ?DOMElement
    {
        $cur = $node->parentNode;
        while ($cur instanceof DOMElement) {
            if ($cur->nodeName === 'body' || $cur->getAttribute('id') === RoleBlockDetectionService::ROOT_ID) {
                return null;
            }
            if (in_array($cur->nodeName, $blockTags, true)) {
                return $cur;
            }
            $cur = $cur->parentNode;
        }
        return null;
    }

    /**
     * For each role, pick the canonical cluster — the one we loop when the
     * role has more than one disjoint cluster in document order. Largest-
     * field-count wins; ties broken by FIRST-occurring cluster (lowest
     * cluster_ordinal) since the main body block typically appears later
     * in the document than the opening-paragraph stray reference but
     * carries more fields. Returns a `role → canonical_cluster_ordinal`
     * map; absent role → ordinal 0.
     *
     * @return array<string, int>
     */
    private function resolveCanonicalClusterPerRole(Collection $boundaries): array
    {
        $best = [];
        foreach ($boundaries as $b) {
            $role = $b['role_token'];
            $count = (int) ($b['field_count'] ?? 0);
            if (!isset($best[$role]) || $count > $best[$role]['count']) {
                $best[$role] = [
                    'ordinal' => $b['cluster_ordinal'],
                    'count'   => $count,
                ];
            }
        }
        $out = [];
        foreach ($best as $role => $info) {
            $out[$role] = $info['ordinal'];
        }
        return $out;
    }

    /**
     * Group recipients by canonical role-token (with twin aliasing).
     * The map keys are populated for BOTH the raw and canonical tokens so
     * lookups by either resolve to the same list.
     *
     * @param  Collection<int, SignatureRequest>                  $recipients
     * @return array<string, Collection<int, SignatureRequest>>
     */
    private function groupRecipientsByRole(Collection $recipients): array
    {
        $byRole = [];
        foreach ($recipients as $r) {
            $role = strtolower((string) ($r->party_role ?? ''));
            if ($role === '') {
                continue;
            }
            $byRole[$role] ??= collect();
            $byRole[$role]->push($r);
            $twin = $this->canonicalTwin($role);
            if ($twin !== null && $twin !== $role) {
                $byRole[$twin] ??= collect();
                $byRole[$twin]->push($r);
            }
        }
        // Sort each bucket by role_index so duplication respects ordering.
        foreach ($byRole as $role => $col) {
            $byRole[$role] = $col->sortBy(fn(SignatureRequest $r) => $r->role_index ?? 1)->values();
        }
        return $byRole;
    }

    /**
     * After a detect→serialise→reload roundtrip the DOMElement refs in the
     * boundary's instance_groups are stale. Re-resolve them against the
     * supplied DOMDocument by matching `data-field` attribute.
     *
     * @param  array<int, list<array{field_name:string,sub_name:?string,node:?DOMElement}>> $groups
     * @return array<int, list<array{field_name:string,sub_name:?string,node:DOMElement}>>
     */
    private function rehydrateInstanceGroups(DOMDocument $dom, array $groups, string $role): array
    {
        $xpath = new DOMXPath($dom);
        $out = [];
        foreach ($groups as $idx => $fields) {
            $out[$idx] = [];
            foreach ($fields as $f) {
                // Use first-occurrence semantics; if the same field name
                // appears more than once (rare but possible), the boundary
                // logic still operates on the cluster as a unit.
                $name = $f['field_name'];
                $nodes = $xpath->query('//*[@data-field="' . str_replace('"', '', $name) . '"]');
                if ($nodes === false || $nodes->length === 0) {
                    continue;
                }
                $node = $nodes->item(0);
                if (!$node instanceof DOMElement) {
                    continue;
                }
                $out[$idx][] = [
                    'field_name' => $name,
                    'sub_name'   => $f['sub_name'],
                    'node'       => $node,
                ];
            }
        }
        return $out;
    }

    /**
     * Find the smallest DOMElement that contains EVERY field with idx=$idx
     * in $instanceGroups AND no field of any other idx for the same role.
     */
    private function findInstanceSubtree(
        DOMDocument $dom,
        array $instanceGroups,
        int $idx,
        string $role,
    ): ?DOMElement {
        $targets = $instanceGroups[$idx] ?? [];
        if (count($targets) === 0) {
            return null;
        }
        $xpath = new DOMXPath($dom);

        // LCA of all idx=$idx nodes.
        $lca = $targets[0]['node'];
        for ($i = 1; $i < count($targets); $i++) {
            $a = $lca;
            $b = $targets[$i]['node'];
            $ancestors = [];
            $cur = $a;
            while ($cur instanceof DOMElement) {
                $ancestors[spl_object_hash($cur)] = $cur;
                $parent = $cur->parentNode;
                $cur = $parent instanceof DOMElement ? $parent : null;
            }
            $cur = $b;
            $found = null;
            while ($cur instanceof DOMElement) {
                if (isset($ancestors[spl_object_hash($cur)])) {
                    $found = $cur;
                    break;
                }
                $parent = $cur->parentNode;
                $cur = $parent instanceof DOMElement ? $parent : null;
            }
            if ($found === null) {
                return null;
            }
            $lca = $found;
        }
        // If the LCA is itself a data-field element (single idx=K field
        // sitting alone), walk up to its wrapper so the cloned subtree
        // carries the surrounding markup (heading, paragraphs) and not
        // just a bare span. Verify the wrapper still contains only
        // idx=$idx fields for this role.
        if ($lca->hasAttribute('data-field') && $lca->parentNode instanceof DOMElement) {
            $candidate = $lca->parentNode;
            if (
                $candidate->nodeName !== 'body'
                && $candidate->getAttribute('id') !== RoleBlockDetectionService::ROOT_ID
                && $this->subtreeOnlyContainsRoleIndex($candidate, $role, $idx)
            ) {
                $lca = $candidate;
            }
        }
        // Ensure the LCA doesn't contain any other-idx field for the same role.
        if (!$this->subtreeOnlyContainsRoleIndex($lca, $role, $idx)) {
            return null;
        }
        // Bail if LCA is the body wrapper.
        if ($lca->nodeName === 'body' || $lca->getAttribute('id') === RoleBlockDetectionService::ROOT_ID) {
            return null;
        }
        return $lca;
    }

    /**
     * @return bool true when $node's subtree contains only data-field
     *              elements whose role-base is $role AND instance_index
     *              is $idx (foreign-role fields are tolerated).
     */
    private function subtreeOnlyContainsRoleIndex(DOMElement $node, string $role, int $idx): bool
    {
        $xpath = new DOMXPath($node->ownerDocument);
        $allFields = $xpath->query('.//*[@data-field] | .//*[@data-field-name]', $node);   // both shapes
        if ($allFields === false) {
            return false;
        }
        foreach ($allFields as $f) {
            if (!$f instanceof DOMElement) {
                continue;
            }
            $parsed = $this->detector->parseFieldName($f->getAttribute('data-field'));
            if ($parsed['role_base'] !== $role) {
                continue;
            }
            if ($parsed['instance_index'] !== $idx) {
                return false;
            }
        }
        return true;
    }

    /**
     * Case A path — duplicate the LCA block once per recipient, stamp each
     * clone with its identity + per-instance data-field suffix, prepend a
     * section header, pre-fill from the recipient's contact, then replace
     * the original block with the concatenated clones.
     *
     * @param  Collection<int, SignatureRequest> $recipients
     */
    private function duplicateBlockForRecipients(
        DOMDocument $dom,
        DOMElement $blockNode,
        string $role,
        Collection $recipients,
        bool $isSales,
        int $totalInstances,
    ): void {
        $parent = $blockNode->parentNode;
        if (!$parent instanceof DOMNode) {
            return;
        }
        $clones = [];
        $n = 0;
        foreach ($recipients as $recipient) {
            $n++;
            $clone = $blockNode->cloneNode(true);
            if (!$clone instanceof DOMElement) {
                continue;
            }
            $this->mutateCloneForInstance(
                $dom,
                $clone,
                $role,
                $n,
                $totalInstances,
                $recipient,
                $isSales,
                strippingForeignIndices: false,
                sourceInstanceIndex: 1,
            );
            $clones[] = $clone;
        }
        // Insert each clone immediately before the original, then remove
        // the original.
        foreach ($clones as $clone) {
            $parent->insertBefore($clone, $blockNode);
        }
        $parent->removeChild($blockNode);
    }

    /**
     * Group-duplication path — when multiple block-units share a parent
     * (e.g. address line + phone+email line both `<div class="corex-clause">`
     * siblings under `<div class="corex-page">`), they form one
     * recipient-instance group. For each recipient, clone EVERY unit
     * in the group as a sequence; prepend the "Seller N: Name"
     * sub-heading ONLY to the first clone in each recipient's
     * sequence so the layout reads:
     *
     *   Seller 1: James
     *     <address line clone>
     *     <phone+email line clone>
     *   Seller 2: Steve
     *     <address line clone>
     *     <phone+email line clone>
     *
     * Single-unit groups still flow through `duplicateBlockForRecipients`
     * (one header per clone == one header per recipient anyway).
     *
     * @param  list<DOMElement>                  $groupUnits  consecutive sibling units
     * @param  Collection<int, SignatureRequest> $recipients
     */
    private function duplicateUnitGroupForRecipients(
        DOMDocument $dom,
        array $groupUnits,
        string $role,
        Collection $recipients,
        bool $isSales,
        int $totalInstances,
    ): void {
        if (empty($groupUnits)) {
            return;
        }
        $firstUnit = $groupUnits[0];
        $parent = $firstUnit->parentNode;
        if (!$parent instanceof DOMNode) {
            return;
        }
        $allClones = [];
        $n = 0;
        foreach ($recipients as $recipient) {
            $n++;
            foreach ($groupUnits as $unitIdx => $unit) {
                $clone = $unit->cloneNode(true);
                if (!$clone instanceof DOMElement) {
                    continue;
                }
                // Only the FIRST clone in this recipient's sequence
                // gets the prepended "Seller N: Name" sub-heading.
                // Subsequent clones in the group inherit the same
                // identity stamps + per-instance data-field suffix
                // (so the JS save endpoint, the editable-scope
                // resolver, and the visual instance-wrapper class all
                // still light up correctly) — they just don't print
                // their own header.
                $this->mutateCloneForInstance(
                    $dom,
                    $clone,
                    $role,
                    $n,
                    $totalInstances,
                    $recipient,
                    $isSales,
                    strippingForeignIndices: false,
                    sourceInstanceIndex: 1,
                    prependHeader: ($unitIdx === 0),
                );
                $allClones[] = $clone;
            }
        }
        // Insert every clone (full recipient sequences in order)
        // before the first original unit, then remove every original.
        foreach ($allClones as $clone) {
            $parent->insertBefore($clone, $firstUnit);
        }
        foreach ($groupUnits as $unit) {
            if ($unit->parentNode === $parent) {
                $parent->removeChild($unit);
            }
        }
    }

    /**
     * Inline-list path — for clusters where the fields all live inside
     * a single block-unit (a prose sentence with field placeholders).
     * The block-unit is NOT cloned; the FIELD-SPAN RANGE inside it is
     * REPLACED with ONE composite span per recipient, joined by
     * " and " between recipients.
     *
     * Per Johan's spec: respect what the template author selected for
     * the opening paragraph. The CDS builder lets the author select a
     * "full seller details" composite (name + surname + ID); the
     * blade-generator may emit that as multiple fragmented sub-spans
     * (seller_first_name + seller_last_name + seller_id_number) with
     * inconsistent whitespace. This method DOES NOT process the
     * fragmented sub-spans individually — instead it composes the
     * recipient's full identity string from the contact record and
     * replaces the entire field range with that single composite per
     * recipient.
     *
     * Composite format: "{First} {Last} (ID: {id_number})". Falls back
     * to signer_name when contact data is incomplete; drops the
     * "(ID: …)" suffix when id_number is empty.
     *
     * Template 111 opening paragraph:
     *
     *   <span class="corex-clause-text">
     *     I / We&nbsp;
     *     <span data-field="seller_last_name">…</span>
     *     <span data-field="seller_id_number">…</span>
     *     , the undersigned …
     *   </span>
     *
     * For 2 sellers the post-fix output renders:
     *
     *   I / We James Van Der Merwe (ID: 3112) and Steve Jobs (ID: 6789),
     *   the undersigned …
     *
     * The composite is correct even when the blade only has
     * (last_name + id_number) spans, because the composition uses the
     * contact's full data — not the sub-spans the blade-generator
     * happened to emit.
     *
     * @param  list<array{field_name:string,sub_name:?string,node:DOMElement}> $fields
     * @param  Collection<int, SignatureRequest>                                $recipients
     */
    private function inlineListClusterForRecipients(
        DOMDocument $dom,
        array $fields,
        string $role,
        Collection $recipients,
        int $totalInstances,
    ): void {
        if (count($fields) === 0 || $recipients->isEmpty()) {
            return;
        }

        $firstField = $fields[0]['node'];
        $lastField  = end($fields)['node'];
        $parent     = $firstField->parentNode;
        if (!$parent instanceof DOMNode) {
            return;
        }

        // Collect the inline range from firstField → lastField
        // (inclusive). We REMOVE this range and replace it with
        // composite-per-recipient spans.
        $rangeNodes = [];
        $cur = $firstField;
        while ($cur !== null) {
            $rangeNodes[] = $cur;
            if ($cur === $lastField) {
                break;
            }
            $cur = $cur->nextSibling;
        }
        if (empty($rangeNodes)) {
            return;
        }

        // Build the replacement sequence:
        //   <composite-span-r1> and <composite-span-r2> and …
        $newNodes = [];
        $n = 0;
        foreach ($recipients->values() as $recipient) {
            $n++;
            if ($n > 1) {
                $newNodes[] = $dom->createTextNode(' and ');
            }
            $newNodes[] = $this->buildRecipientCompositeSpan(
                $dom,
                $role,
                $n,
                $recipient,
            );
        }

        // Insert the replacement sequence BEFORE the first original
        // node, then remove every original node in the range.
        foreach ($newNodes as $node) {
            $parent->insertBefore($node, $firstField);
        }
        foreach ($rangeNodes as $orig) {
            if ($orig->parentNode === $parent) {
                $parent->removeChild($orig);
            }
        }
    }

    /**
     * Build a single composite span for one recipient in an inline-list
     * cluster. Pulls full contact data so the composite is correct
     * regardless of which sub-fields the blade-generator emitted.
     *
     * The span itself stamps the recipient identity + role-token so
     * the editable-scope resolver and the visual instance-wrapper
     * styles still apply.
     */
    private function buildRecipientCompositeSpan(
        DOMDocument $dom,
        string $role,
        int $instanceIndex,
        SignatureRequest $recipient,
    ): \DOMElement {
        $contact = $this->resolveContact($recipient);

        $first = trim((string) ($contact->first_name ?? ''));
        $last  = trim((string) ($contact->last_name ?? ''));
        $id    = trim((string) ($contact->id_number ?? ''));

        $name = trim($first . ' ' . $last);
        if ($name === '') {
            // Contact missing or unnamed — fall back to signer_name
            // (always populated from the wizard's recipient list).
            $name = trim((string) ($recipient->signer_name ?? ''));
        }

        $composite = $name;
        if ($id !== '') {
            $composite = $name . ' (ID: ' . $id . ')';
        }

        $span = $dom->createElement('span');
        $span->setAttribute('class', 'corex-field-value recipient-inline-composite');
        $span->setAttribute('data-recipient-identity', $role . '_' . $instanceIndex);
        $span->setAttribute('data-role-token', $role);
        $span->setAttribute('data-recipient-composite', '1');
        $span->appendChild($dom->createTextNode($composite));
        return $span;
    }

    /**
     * Stamp a single inline field-span with the recipient's identity
     * + pre-fill from contact. Mirrors `mutateCloneForInstance`'s
     * per-field mutation but operates on individual nodes (the
     * inline-list path doesn't have a block-clone wrapper).
     */
    private function stampInlineFieldForRecipient(
        DOMElement $fieldNode,
        string $role,
        int $instanceIndex,
        ?Contact $contact,
    ): void {
        $origName = $fieldNode->getAttribute('data-field');
        // Strip any pre-existing __r{n} suffix (cloned nodes carry the
        // previous stamping) before re-stamping for this instance.
        $logicalName = preg_replace('/__r\d+$/', '', $origName);
        $parsed = $this->detector->parseFieldName($logicalName);
        if ($parsed['role_base'] === null) {
            return;
        }
        $fieldNode->setAttribute('data-field', $logicalName . '__r' . $instanceIndex);
        $fieldNode->setAttribute('data-original-field', $logicalName);
        $fieldNode->setAttribute('data-recipient-identity', $role . '_' . $instanceIndex);
        $fieldNode->setAttribute('data-role-token', $role);
        if ($contact !== null && $parsed['sub_name'] !== null) {
            $value = $this->resolveContactValue($contact, $parsed['sub_name']);
            if ($value !== null) {
                $this->replaceTextContent($fieldNode, $value);
            }
        }
    }

    /**
     * Insert $newNode immediately after $referenceNode under $parent;
     * return the inserted node so the caller can chain further
     * inserts. Handles the "$referenceNode is the last child" case.
     */
    private function insertAfterNode(DOMNode $parent, DOMNode $newNode, DOMNode $referenceNode): DOMNode
    {
        if ($referenceNode->nextSibling !== null) {
            $parent->insertBefore($newNode, $referenceNode->nextSibling);
        } else {
            $parent->appendChild($newNode);
        }
        return $newNode;
    }

    /**
     * Case D.2 path — duplicate the idx=K subtree for instances K+1..N.
     *
     * @param  Collection<int, SignatureRequest> $recipients   (all recipients, ordered by role_index)
     */
    private function duplicateSubtreeForIndices(
        DOMDocument $dom,
        DOMElement $subtree,
        string $role,
        Collection $recipients,
        bool $isSales,
        int $fromIndex,
        int $toIndex,
        int $totalInstances,
    ): void {
        $parent = $subtree->parentNode;
        if (!$parent instanceof DOMNode) {
            return;
        }
        $insertAfter = $subtree;
        for ($n = $fromIndex; $n <= $toIndex; $n++) {
            $recipient = $recipients->get($n - 1);
            $clone = $subtree->cloneNode(true);
            if (!$clone instanceof DOMElement) {
                continue;
            }
            // sourceInstanceIndex = (fromIndex - 1) because we're cloning
            // the LAST hardcoded block which by definition has idx=K.
            $this->mutateCloneForInstance(
                $dom,
                $clone,
                $role,
                $n,
                $totalInstances,
                $recipient,
                $isSales,
                strippingForeignIndices: false,
                sourceInstanceIndex: $fromIndex - 1,
            );
            // Insert after the previous block (subtree or last clone).
            if ($insertAfter->nextSibling !== null) {
                $parent->insertBefore($clone, $insertAfter->nextSibling);
            } else {
                $parent->appendChild($clone);
            }
            $insertAfter = $clone;
        }
    }

    /**
     * Apply per-instance mutations to a cloned block: rewrite data-field
     * names for DOM uniqueness, stamp identity attrs, prepend the header,
     * pre-fill from contact.
     */
    private function mutateCloneForInstance(
        DOMDocument $dom,
        DOMElement $clone,
        string $role,
        int $instanceIndex,
        int $totalInstances,
        ?SignatureRequest $recipient,
        bool $isSales,
        bool $strippingForeignIndices,
        int $sourceInstanceIndex = 1,
        bool $prependHeader = true,
    ): void {
        $xpath = new DOMXPath($dom);

        // Visual-layout contract — mark the clone root with the
        // `recipient-instance` class + `data-recipient-instance`
        // attribute so the shared CSS in docuperfect-recipient-blocks.css
        // can target every clone uniformly across the three consumer
        // views (wizard Step 4 preview, wizard Step 5 fill-and-review,
        // recipient signing surface). The class is additive — the
        // template's original class string is preserved so existing
        // layout rules still apply.
        $identity = $role . '_' . $instanceIndex;
        $clone->setAttribute('data-recipient-instance', $identity);
        $existingClass = $clone->getAttribute('class');
        $clone->setAttribute(
            'class',
            trim($existingClass . ' recipient-instance recipient-instance--' . $role)
        );

        // Label rewrite — rewrite indexed role labels from the source
        // instance to the target instance. This closes B2.5's known
        // limitation: Case D.2 clones used to carry the source block's
        // static "Seller 2" text into a "Seller 4" instance. Only the
        // indexed form is rewritten ("Seller 2" → "Seller 4"), bare
        // "Seller" left alone to avoid clobbering common labels like
        // "Seller Address". Operates on text nodes only (not attributes,
        // not input values) so user-entered data is never touched.
        if ($sourceInstanceIndex !== $instanceIndex) {
            $this->rewriteCloneLabels($clone, $role, $sourceInstanceIndex, $instanceIndex, $isSales);
        }

        // descendant-or-self so a clone whose root IS the field element
        // (single-field cluster edge case) still gets stamped.
        $fields = $xpath->query('descendant-or-self::*[@data-field] | descendant-or-self::*[@data-field-name]', $clone);   // both shapes
        if ($fields === false) {
            return;
        }
        $contact = $this->resolveContact($recipient);
        foreach ($fields as $f) {
            if (!$f instanceof DOMElement) {
                continue;
            }
            $origName = $f->getAttribute('data-field');
            // Resolve through the canonical bridge, NOT parseFieldName() alone. A CDS-named
            // field (`contact.first_name` + `data-contact-type="Seller"`) is invisible to
            // parseFieldName, so every such field was skipped here: the block cloned, but the
            // fields inside kept the SAME data-field across clones — colliding in the DOM, so
            // the second seller's input would land on the first seller's field — and were
            // never identity-stamped or prefilled. Same root cause as the normalizer's.
            $parsed = $this->detector->resolveFieldElement($f);
            if ($parsed['role_base'] === null) {
                continue;
            }
            // Mangle the data-field for DOM uniqueness across clones.
            $f->setAttribute('data-field', $origName . '__r' . $instanceIndex);
            $f->setAttribute('data-original-field', $origName);
            // Stamp identity.
            $f->setAttribute('data-recipient-identity', $role . '_' . $instanceIndex);
            $f->setAttribute('data-role-token', $role);
            // Pre-fill from contact if mapping recognised.
            if ($contact !== null && $parsed['sub_name'] !== null) {
                $value = $this->resolveContactValue($contact, $parsed['sub_name']);
                // AT-292 — headline couple's-mandate fix. When the matched
                // Contact has no id_number, fall back to the ID the signer
                // typed in the wizard (persisted on the SignatureRequest) so
                // the second seller still renders their ID even on historical
                // data where the span was never baked with it.
                if ($value === null
                    && $recipient !== null
                    && in_array($parsed['sub_name'], ['id', 'id_number'], true)
                ) {
                    $value = $this->blankToNull($recipient->signer_id_number);
                }
                if ($value !== null) {
                    $this->replaceTextContent($f, $value);
                }
            }
        }
        // Header gating — single-unit paths (duplicateBlockForRecipients,
        // duplicateSubtreeForIndices) always prepend; the new group-
        // duplication path prepends ONLY for the first clone in each
        // recipient's sequence so consecutive same-role lines render
        // under one shared "Seller N: Name" sub-heading.
        if ($prependHeader) {
            $this->prependSectionHeader($dom, $clone, $role, $instanceIndex, $totalInstances, $isSales, $recipient);
        }
    }

    /**
     * Prepend a recipient-block header so the rendered signing surface
     * shows "Seller - James Van Der Merwe" / "Lessor - Liam" etc.
     * above each block-duplicated instance.
     *
     * Format per Johan's spec: `{role_base_label} - {signer_name}`.
     * The indexed form ("Seller 1:") was used in an earlier iteration
     * but doesn't match the agency-facing convention — the opening
     * paragraph already lists names inline with "and"; the main block
     * heading just identifies whose data follows. We pass index=1 +
     * totalInstances=1 to `roleDisplayLabel` so it returns the
     * singleton form ("Seller" not "Seller 1"), then append " - Name".
     *
     * Fallback when no recipient is supplied (synthetic templates,
     * orphan-stamping paths): "{role_base_label} {instanceIndex}" so
     * the header still distinguishes instances visually.
     */
    private function prependSectionHeader(
        DOMDocument $dom,
        DOMElement $blockEl,
        string $role,
        int $instanceIndex,
        int $totalInstances,
        bool $isSales,
        ?SignatureRequest $recipient,
    ): void {
        $baseLabel = Template::roleDisplayLabel($role, $isSales, 1, 1);
        if ($recipient !== null && !empty($recipient->signer_name)) {
            $label = $baseLabel . ' - ' . $recipient->signer_name;
        } else {
            $label = $baseLabel . ' ' . $instanceIndex;
        }
        $h = $dom->createElement('h4');
        // Dual class — `recipient-block-header` for backward compat with
        // any existing CSS, `recipient-instance-label` is the new
        // canonical name targeted by docuperfect-recipient-blocks.css
        // (the shared visual contract across Step 4 / Step 5 / signing
        // view).
        $h->setAttribute('class', 'recipient-block-header recipient-instance-label');
        $h->setAttribute('data-recipient-identity', $role . '_' . $instanceIndex);
        $h->appendChild($dom->createTextNode($label));
        if ($blockEl->firstChild !== null) {
            $blockEl->insertBefore($h, $blockEl->firstChild);
        } else {
            $blockEl->appendChild($h);
        }
    }

    /**
     * Replace a span/element's visible text content while preserving any
     * non-text child structure (rare, but defensive).
     */
    private function replaceTextContent(DOMElement $el, string $value): void
    {
        // Remove all current children, append a single text node.
        while ($el->firstChild !== null) {
            $el->removeChild($el->firstChild);
        }
        $el->appendChild($el->ownerDocument->createTextNode($value));
    }

    /**
     * Stamp a single field node in place (no duplication, no pre-fill).
     */
    private function stampFieldNode(DOMElement $node, string $role, int $idx, bool $isOrphan): void
    {
        $node->setAttribute('data-recipient-identity', $role . '_' . $idx);
        $node->setAttribute('data-role-token', $role);
        if ($isOrphan) {
            $node->setAttribute('data-orphan-recipient', '1');
        }
    }

    /**
     * Resolve a recipient's linked Contact, or null when none.
     */
    private function resolveContact(?SignatureRequest $recipient): ?Contact
    {
        if ($recipient === null || empty($recipient->contact_id)) {
            return null;
        }
        return Contact::find($recipient->contact_id);
    }

    /**
     * Walk text nodes inside the clone and rewrite indexed role labels.
     *
     * Source label uses `totalInstancesForRole = 99` to force the indexed
     * form (so a singleton block's "Seller" doesn't accidentally match —
     * we only rewrite text the author explicitly labelled with an index).
     * Target label uses the same trick so cloned blocks read "Seller 3"
     * rather than the unindexed singleton.
     *
     * Skips text nodes inside <input>, <textarea>, <select>, <script>,
     * <style>, <option> — user-entered data and machine-readable text
     * must never be silently rewritten. Skips matches inside attribute
     * values (handled by XPath text() axis which only selects text node
     * children, never attributes).
     */
    private function rewriteCloneLabels(
        DOMElement $clone,
        string $roleToken,
        int $sourceIndex,
        int $targetIndex,
        bool $isSales,
    ): void {
        $sourceLabel = Template::roleDisplayLabel($roleToken, $isSales, $sourceIndex, 99);
        $targetLabel = Template::roleDisplayLabel($roleToken, $isSales, $targetIndex, 99);
        if ($sourceLabel === $targetLabel) {
            return;
        }
        $pattern = '/\b' . preg_quote($sourceLabel, '/') . '\b/i';

        $xpath = new DOMXPath($clone->ownerDocument);
        $skipParents = ['input', 'textarea', 'select', 'option', 'script', 'style'];
        $textNodes = $xpath->query('.//text()', $clone);
        if ($textNodes === false) {
            return;
        }
        foreach ($textNodes as $textNode) {
            $parent = $textNode->parentNode;
            if ($parent instanceof DOMElement && in_array(strtolower($parent->nodeName), $skipParents, true)) {
                continue;
            }
            $value = $textNode->nodeValue ?? '';
            if ($value === '' || !preg_match($pattern, $value)) {
                continue;
            }
            $textNode->nodeValue = preg_replace($pattern, $targetLabel, $value);
        }
    }

    /**
     * Map a field's sub-name to a contact column. Returns null when the
     * sub-name isn't recognised — caller leaves the original span text.
     */
    private function resolveContactValue(Contact $contact, string $subName): ?string
    {
        $key = strtolower($subName);
        // AT-292 — return null (NOT '') whenever the Contact column is empty so
        // the caller's `if ($value !== null)` guard PRESERVES the value the
        // wizard baked into merged_html instead of overwriting it with blank.
        // A couple's second seller is commonly matched to an EXISTING Contact
        // whose id_number is empty; the typed ID lives in the span and must
        // survive. The pre-fix `(string)` casts turned an empty column into ''
        // which passed the guard and wiped the ID (name/email/address/phone too).
        switch ($key) {
            case 'first_name':
                return $this->blankToNull($contact->first_name);
            case 'last_name':
            case 'surname':
                return $this->blankToNull($contact->last_name);
            case 'name':
            case 'full_name':
            // The composite full-name column the CDS generator really emits for a party's
            // name blank — without this the seller's name simply never prefills.
            case 'first_name+last_name':
                return $this->blankToNull(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''));
            case 'name_surname_id':
                $full = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''));
                $id = trim((string) ($contact->id_number ?? ''));
                return $this->blankToNull($id !== '' ? ($full . ' (ID: ' . $id . ')') : $full);
            case 'id':
            case 'id_number':
                return $this->blankToNull($contact->id_number);
            case 'email':
                return $this->blankToNull($contact->email);
            case 'phone':
            case 'cell':          // AT-292 — a `seller_cell` field was recognised by the normalizer but had no case here, so it never re-sourced (couples showed seller 1's number).
            case 'cell_phone':
            case 'mobile':
                return $this->blankToNull($contact->phone);
            case 'address':
            case 'address_1':
            case 'address_line_1':
            case 'physical_address':
                return $this->blankToNull($contact->address);
        }
        return null;
    }

    /**
     * AT-292 — normalise a Contact column to a non-empty trimmed string or
     * null. Returning null (rather than '') is the choke point that lets the
     * per-recipient prefill preserve the wizard-baked span for any identity
     * field the Contact happens to be missing.
     */
    private function blankToNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }
}
