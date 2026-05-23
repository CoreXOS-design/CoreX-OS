<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\Deal\DealMoneyLineChanged;
use App\Models\Deal;
use App\Models\DealMoneyLine;
use Illuminate\Support\Facades\Auth;

/**
 * Emits Deal\DealMoneyLineChanged whenever a DealMoneyLine row is created,
 * updated, or deleted. The recalc command (deals:recalc-money-lines) rewrites
 * these rows; this observer therefore also fires after recalc completes.
 *
 * Spec: .ai/specs/corex-domain-events-spec.md (Wave 6 deferred wiring).
 */
class DealMoneyLineObserver
{
    public function created(DealMoneyLine $line): void
    {
        $this->fire($line, 'created', $line->getAttributes());
    }

    public function updated(DealMoneyLine $line): void
    {
        $dirty = $line->getChanges();
        if (empty($dirty)) {
            return;
        }
        $this->fire($line, 'updated', $dirty);
    }

    public function deleted(DealMoneyLine $line): void
    {
        $this->fire($line, 'deleted', []);
    }

    private function fire(DealMoneyLine $line, string $action, array $diff): void
    {
        $deal = Deal::withoutGlobalScopes()->find($line->deal_id);
        if (!$deal) {
            return;
        }
        event(new DealMoneyLineChanged(
            deal: $deal,
            line: $line,
            change: ['action' => $action, 'diff' => $diff],
            actorUserId: Auth::id(),
        ));
    }
}
