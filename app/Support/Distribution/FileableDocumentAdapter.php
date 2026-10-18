<?php

namespace App\Support\Distribution;

use App\Contracts\SignedDocumentDistributable;
use App\Models\Property;
use App\Models\User;

/**
 * Conductor brief 2026-09-29 — a one-off, minimal SignedDocumentDistributable
 * for filing a document to a property via
 * App\Services\Distribution\SignedDocumentDistributionService::fileToProperty()
 * when the caller has no natural "distributable" domain object of its own
 * (the per-party wet-ink SCAN — a single signature row's own evidence, not
 * the inventory/inspection's whole signed report, which RentalInventory/
 * RentalInspection already model directly).
 *
 * fileToProperty() only ever calls distributionProperty(), distributionSourceType(),
 * and distributionSourceId() — never any of the email/public-link methods
 * below, so those are safe, inert stand-ins rather than real implementations.
 * This adapter is NOT for anything that emails parties or generates a public
 * link; use the real domain object (RentalInventory/RentalInspection) for that.
 */
final class FileableDocumentAdapter implements SignedDocumentDistributable
{
    public function __construct(
        private readonly ?Property $property,
        private readonly string $sourceType,
        private readonly int $sourceId,
    ) {}

    public function distributionProperty(): ?Property
    {
        return $this->property;
    }

    public function distributionRecipients(): array
    {
        return [];
    }

    public function distributionAgent(): ?User
    {
        return null;
    }

    public function distributionSubject(): string
    {
        return '';
    }

    public function distributionDocumentLabel(): string
    {
        return '';
    }

    public function distributionSourceType(): string
    {
        return $this->sourceType;
    }

    public function distributionSourceId(): int
    {
        return $this->sourceId;
    }

    public function hasValidPublicLink(): bool
    {
        return true;
    }

    public function generatePublicLink(): string
    {
        throw new \LogicException('FileableDocumentAdapter does not support public links — use the real distributable domain object.');
    }

    public function publicShareUrl(): ?string
    {
        return null;
    }
}
