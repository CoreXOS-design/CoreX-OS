<?php

namespace App\Console\Commands;

use App\Models\RentalCatalogueItem;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Services\Rentals\RentalPricingService;
use Illuminate\Console\Command;

/**
 * .ai/specs/rental-work-orders.md §17.39 — re-derive the selling price of job card lines that were priced from a catalogue item
 * which is (now) priced by MARKUP: selling = the line's own cost + the item's markup. Meant for lines saved before a markup item
 * was priced this way (they carry the catalogue's derived, often R0.00, selling). Only lines whose source is the catalogue
 * (`catalogue_price` / `catalogue_markup`) are touched: a price set by hand, a line markup, a card markup are never moved, and a
 * completed or cancelled card never changes. Deliberately needs --job-card or --all, and --dry-run shows the change without making it.
 */
class RederiveCatalogueMarkupLines extends Command
{
    protected $signature = 'rentals:rederive-catalogue-markup-lines {--job-card= : Only this job card id} {--all : Every open job card (required when no --job-card)} {--dry-run : Show what would change, change nothing}';

    protected $description = 'Re-derive selling on job card lines priced from a markup-priced catalogue item (cost + markup on the line\'s own cost)';

    public function handle(RentalPricingService $pricing): int
    {
        $cardId = $this->option('job-card');
        if (! $cardId && ! $this->option('all')) {
            $this->error('Say which cards: --job-card=<id> or --all (use --dry-run first).');

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');

        $itemIds = RentalCatalogueItem::withoutGlobalScopes()->withTrashed()
            ->where('pricing_driver', RentalCatalogueItem::DRIVER_MARKUP)->whereNotNull('markup_value')->pluck('id');

        $lines = RentalJobCardLine::withoutGlobalScopes()->whereNull('deleted_at')
            ->whereIn('selling_basis', [RentalJobCardLine::BASIS_CATALOGUE_PRICE, RentalJobCardLine::BASIS_CATALOGUE_MARKUP])
            ->where('office_status', RentalJobCardLine::OFFICE_ACCEPTED)
            ->whereIn('rental_catalogue_item_id', $itemIds)
            ->when($cardId, fn ($q) => $q->where('rental_job_card_id', (int) $cardId))
            ->orderBy('rental_job_card_id')->orderBy('id')->get();

        $changed = 0;
        $touchedCards = [];
        foreach ($lines as $line) {
            $card = RentalJobCard::withoutGlobalScopes()->find($line->rental_job_card_id);
            if (! $card || $card->trashed() || $card->isClosed()) {
                $this->line("skip line {$line->id} (card {$line->rental_job_card_id} is closed or archived)");

                continue;
            }
            $line->setRelation('jobCard', $card);
            $before = $line->unit_price;
            $resolution = $pricing->resolveSelling($line, $card);
            $after = $resolution->unitPrice;

            $this->line(sprintf('card %d line %d "%s": cost %s, selling %s -> %s (%s)', $card->id, $line->id, $line->description,
                $line->unit_cost ?? '—', $before ?? '—', $after ?? '—', $resolution->basis));

            if ($after === null || ($before !== null && abs((float) $before - $after) < 0.005 && $line->selling_basis === $resolution->basis)) {
                continue;
            }
            $changed++;
            if (! $dry) {
                $pricing->repriceLine($line);
                $touchedCards[$card->id] = $card;
            }
        }
        foreach ($touchedCards as $card) {
            $card->recalcTotal();
        }

        $this->info(($dry ? 'DRY RUN - would change ' : 'Changed ') . "{$changed} line(s) of {$lines->count()} checked.");

        return self::SUCCESS;
    }
}
