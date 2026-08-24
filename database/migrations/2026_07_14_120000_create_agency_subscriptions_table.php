<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agency Billing (AT-11) — one subscription row per agency.
 *
 * Spec: .ai/specs/agency-billing.md §5
 *
 * Holds only what CANNOT be derived. The seat count, the price and the
 * discount end-date are all COMPUTED at read time (see
 * SubscriptionPricingService) — storing them would let them go stale.
 * What lives here is the commercial decision we made: the last-reconciled
 * plan, an optional custom amount, an optional discount.
 *
 * INVARIANT (spec §3 D5): custom_amount_zar and discount_percent are never
 * both set. Enforced in the UI (mode selector), in validation (`prohibits`),
 * and in the service (write nulls the counterpart; read lets custom win).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_subscriptions', function (Blueprint $table) {
            $table->id();

            // One subscription per agency. Unique, so a double-provision is a
            // DB error rather than a silent second row with different terms.
            $table->foreignId('agency_id')
                ->unique()
                ->constrained('agencies')
                ->cascadeOnDelete();

            // The LAST RECONCILED plan. Drift between this and the plan derived
            // from the live seat count is exactly what triggers the change email.
            $table->string('plan', 20)->default('team');
            $table->timestamp('plan_changed_at')->nullable();

            // Override: when set, THIS is the price. Nullable — and NULL is
            // meaningfully different from 0.00 (0.00 = "free, deliberately").
            $table->decimal('custom_amount_zar', 12, 2)->nullable();
            $table->string('custom_amount_note', 255)->nullable();

            // Discount: a % off the COMPUTED price for a whole number of months.
            // The end date is derived (starts_on + months), never stored — so a
            // discount expires by arithmetic and no cron can forget to end it.
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->unsignedSmallInteger('discount_months')->nullable();
            $table->date('discount_starts_on')->nullable();
            $table->string('discount_note', 255)->nullable();

            // Dev-only. Never rendered on the agency-facing page.
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();   // non-negotiable #1

            $table->index('plan');
        });

        $this->backfillExistingAgencies();
    }

    /**
     * Provision a row for every agency that already exists, with the plan its
     * CURRENT headcount implies.
     *
     * Without this, the first person to open the billing page would trigger a
     * firstOrCreate WRITE inside a GET request on live, and — worse — every
     * pre-existing agency would default to `team` and then immediately "switch"
     * to `agency` on first reconcile, firing a spurious plan-change email to
     * Johan and me for every agency we already have. Seeding the correct plan
     * here means the first reconcile is a no-op, which is what it should be.
     */
    private function backfillExistingAgencies(): void
    {
        $maxTeamSeats = (int) config('corex-billing.team.max_seats', 10);
        $now = now();

        // Live seat count per agency, using the SAME definition as
        // SubscriptionPricingService::billableSeats(): active, not archived.
        $seatCounts = DB::table('users')
            ->selectRaw('agency_id, COUNT(*) AS seats')
            ->whereNotNull('agency_id')
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->groupBy('agency_id')
            ->pluck('seats', 'agency_id');

        $rows = DB::table('agencies')
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn ($agencyId) => [
                'agency_id'       => $agencyId,
                'plan'            => ((int) ($seatCounts[$agencyId] ?? 0)) > $maxTeamSeats ? 'agency' : 'team',
                'plan_changed_at' => $now,
                'created_at'      => $now,
                'updated_at'      => $now,
            ])
            ->all();

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('agency_subscriptions')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_subscriptions');
    }
};
