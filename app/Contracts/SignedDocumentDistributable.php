<?php

namespace App\Contracts;

use App\Models\Property;
use App\Models\User;

/**
 * §41, 2026-09-28 — the contract App\Services\Distribution\
 * SignedDocumentDistributionService is built against. Any model
 * representing a completed, signed document that needs filing to a
 * property, emailing to its parties, and a public share link implements
 * this — RentalInspection is the first (this build); Inventory is the
 * next (cc2). The service knows NOTHING about rental inspections
 * specifically; every inspection-shaped decision (who the parties are,
 * what the subject line says, which property it belongs to) lives on
 * THIS side of the contract, on the model itself.
 *
 * Full usage contract documented in .ai/specs/signed-document-distribution.md
 * — read that file, not this one, before wiring a new consumer.
 */
interface SignedDocumentDistributable
{
    /** The property this document belongs to — filing and the recipient list both anchor here. */
    public function distributionProperty(): ?Property;

    /**
     * Every party who should receive the document, in the order they
     * should appear in a confirm-modal recipient list.
     *
     * @return array<int, array{contact_id: int|null, name: string, email: string, role: string}>
     */
    public function distributionRecipients(): array;

    /** Whose mailbox this sends from by default (the agent CC'd on send, per Johan's ruling). Null falls back to the shared CoreX mailer. */
    public function distributionAgent(): ?User;

    /** The email subject line. */
    public function distributionSubject(): string;

    /** Human label for what this document IS, used in the filename and the confirm modal (e.g. "In-inspection report"). */
    public function distributionDocumentLabel(): string;

    /** Document::source_type value this files under — one fixed string per consumer, e.g. 'rental_inspection_report'. */
    public function distributionSourceType(): string;

    /** Document::source_id value — this record's own primary key. */
    public function distributionSourceId(): int;

    /** Whether a currently-valid (unexpired, unrevoked) public share link already exists. */
    public function hasValidPublicLink(): bool;

    /** Generate (or regenerate) the public share link. ALWAYS overwrites/invalidates any prior link — the service only calls this when hasValidPublicLink() is false, never unconditionally. */
    public function generatePublicLink(): string;

    /** The full, ready-to-share public URL for the current token, or null if none exists. */
    public function publicShareUrl(): ?string;
}
