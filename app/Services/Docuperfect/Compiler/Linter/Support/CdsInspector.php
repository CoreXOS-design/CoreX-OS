<?php

namespace App\Services\Docuperfect\Compiler\Linter\Support;

use App\Support\Docuperfect\Cds\Anchor;
use App\Support\Docuperfect\Cds\Block;
use App\Support\Docuperfect\Cds\Cds;
use App\Support\Docuperfect\Cds\Field;
use App\Support\Docuperfect\Cds\Party;

/**
 * E-Sign Document Compiler — WS1 (Linter gate engine).
 *
 * Read-only navigation helpers over the canonical WS0 {@see Cds} DTO (the sole runtime
 * truth, `App\Support\Docuperfect\Cds`). The linter rules code against the typed DTO;
 * this inspector just saves each rule from re-walking blocks to collect fields/anchors and
 * to enumerate the declared party space.
 *
 * WS0's blocks are FLAT (a Block carries `fields`/`anchors`/`slot`, never nested blocks),
 * so collection is a single pass.
 *
 * INTEGRATION (AT-177): this is the WS1 consumer of WS0's DTO contract — the seam the
 * §12-decision-4 formation put in place ("typed PHP value objects the linter and renderer
 * both code against").
 */
final class CdsInspector
{
    public function __construct(private readonly Cds $cds)
    {
    }

    public function cds(): Cds
    {
        return $this->cds;
    }

    /**
     * Every field paired with its owning block.
     *
     * @return array<int,array{blockId:string,block:Block,field:Field}>
     */
    public function fields(): array
    {
        $out = [];
        foreach ($this->cds->blocks as $block) {
            foreach ($block->fields as $field) {
                $out[] = ['blockId' => $block->blockId, 'block' => $block, 'field' => $field];
            }
        }

        return $out;
    }

    /**
     * Every anchor paired with its owning block id.
     *
     * @return array<int,array{blockId:string,anchor:Anchor}>
     */
    public function anchors(): array
    {
        $out = [];
        foreach ($this->cds->blocks as $block) {
            foreach ($block->anchors as $anchor) {
                $out[] = ['blockId' => $block->blockId, 'anchor' => $anchor];
            }
        }

        return $out;
    }

    /** @return list<Party> */
    public function parties(): array
    {
        return $this->cds->parties;
    }

    /** @return string[] declared party keys (role bases, e.g. "seller", "agent") */
    public function partyKeys(): array
    {
        return array_values(array_map(static fn (Party $p): string => $p->key, $this->cds->parties));
    }

    /** @return string[] distinct declared roles */
    public function roles(): array
    {
        $roles = [];
        foreach ($this->cds->parties as $p) {
            if ($p->role !== '') {
                $roles[$p->role] = true;
            }
        }

        return array_keys($roles);
    }

    /** @return string[] every declared field id */
    public function fieldIds(): array
    {
        $ids = [];
        foreach ($this->fields() as $entry) {
            $id = $entry['field']->fieldId;
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    public function hasFieldId(string $fieldId): bool
    {
        foreach ($this->cds->blocks as $block) {
            foreach ($block->fields as $field) {
                if ($field->fieldId === $fieldId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A declared party addressed by key OR by role base (e.g. "seller" resolves the seller
     * party; so does "seller_1").
     */
    public function partyByKeyOrRoleBase(string $key): ?Party
    {
        $base = \App\Support\Docuperfect\Cds\PartyExpr::roleBase($key);
        foreach ($this->cds->parties as $p) {
            if ($p->key === $key || $p->key === $base || $p->role === $key || $p->role === $base) {
                return $p;
            }
        }

        return null;
    }
}
