<?php

namespace App\Contracts;

/**
 * §45.6 (Build I-4) — an OPTIONAL companion to SignedDocumentDistributable. A document that implements it tells the
 * shared distribution service about parties who SHOULD get a copy but cannot be mailed (no email address on file), so
 * the service records a `skipped` row — with the reason — instead of silently dropping them. Inventory does not
 * implement it and is unaffected.
 */
interface ReportsUnreachableRecipients
{
    /**
     * @return array<int, array{contact_id: int|null, name: string, role: string, reason: string}>
     */
    public function distributionUnreachableRecipients(): array;
}
