<?php

namespace App\Services\DealV2;

use App\Models\DealV2\AgencyServiceProvider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/rentals-faults-work-orders.md §20 - the ONE place a supplier's call-out fee is set. The directory page, the "Add a contractor"
 * popups and the create path all end here, so the rules and the change history can never differ between screens.
 */
class SupplierCalloutFeeService
{
    public const SOURCE_DIRECTORY = 'directory';
    public const SOURCE_POPUP = 'popup';
    public const SOURCE_CREATE = 'create';

    /** The validation rules every screen shares (merge into the screen's own rules). */
    public static function rules(): array
    {
        return [
            'callout_fee_mode' => 'nullable|in:yes,no,unknown',
            'callout_fee_amount' => 'nullable|required_if:callout_fee_mode,yes|numeric|gt:0|max:999999.99',
            'callout_fee_note' => 'nullable|string|max:255',
        ];
    }

    public static function attributeNames(): array
    {
        return ['callout_fee_mode' => 'call-out fee question', 'callout_fee_amount' => 'call-out fee', 'callout_fee_note' => 'call-out note'];
    }

    public static function messages(): array
    {
        return ['callout_fee_amount.required_if' => 'Enter the call-out fee (in rand, including VAT) or choose No / Not known.'];
    }

    /**
     * Set the fee. Yes needs an amount above R0; No / Not known clear the amount and note. Writes a history row only when something changed.
     *
     * @return bool true when the supplier's fee actually changed
     */
    public function apply(AgencyServiceProvider $provider, ?string $mode, mixed $amount, ?string $note, ?User $by, string $source): bool
    {
        $mode = $mode === null || $mode === '' ? AgencyServiceProvider::CALLOUT_UNKNOWN : $mode;
        if (! in_array($mode, AgencyServiceProvider::CALLOUT_MODES, true)) {
            throw ValidationException::withMessages(['callout_fee_mode' => 'Choose Yes, No or Not known.']);
        }

        $amount = $mode === AgencyServiceProvider::CALLOUT_YES && $amount !== null && $amount !== '' ? round((float) str_replace([' ', ','], ['', '.'], (string) $amount), 2) : null;
        if ($mode === AgencyServiceProvider::CALLOUT_YES && ($amount === null || $amount <= 0)) {
            throw ValidationException::withMessages(['callout_fee_amount' => self::messages()['callout_fee_amount.required_if']]);
        }
        $note = $mode === AgencyServiceProvider::CALLOUT_YES ? (trim((string) $note) !== '' ? mb_substr(trim((string) $note), 0, 255) : null) : null;

        $fromMode = $provider->callout_fee_mode ?: AgencyServiceProvider::CALLOUT_UNKNOWN;
        $fromAmount = $provider->callout_fee_amount !== null ? round((float) $provider->callout_fee_amount, 2) : null;
        $fromNote = $provider->callout_fee_note;

        if ($fromMode === $mode && $fromAmount === $amount && $fromNote === $note) {
            return false;
        }

        DB::transaction(function () use ($provider, $mode, $amount, $note, $by, $source, $fromMode, $fromAmount, $fromNote) {
            AgencyServiceProvider::withoutGlobalScopes()->whereKey($provider->id)->update([
                'callout_fee_mode' => $mode, 'callout_fee_amount' => $amount, 'callout_fee_note' => $note,
            ]);
            DB::table('agency_service_provider_callout_history')->insert([
                'agency_id' => (int) $provider->agency_id, 'service_provider_id' => $provider->id, 'user_id' => $by?->id, 'source' => $source,
                'from_mode' => $fromMode, 'from_amount' => $fromAmount, 'from_note' => $fromNote,
                'to_mode' => $mode, 'to_amount' => $amount, 'to_note' => $note, 'created_at' => now(),
            ]);
        });
        $provider->forceFill(['callout_fee_mode' => $mode, 'callout_fee_amount' => $amount, 'callout_fee_note' => $note])->syncOriginal();

        return true;
    }

    /** One plain history line: "12 Oct 2026 14:05 - Jane Agent: Not known -> Charges R750 (waived if the job goes ahead)". */
    public static function describe(object $row): string
    {
        return self::state($row->from_mode, $row->from_amount, $row->from_note) . ' → ' . self::state($row->to_mode, $row->to_amount, $row->to_note);
    }

    private static function state(string $mode, mixed $amount, ?string $note): string
    {
        return match ($mode) {
            AgencyServiceProvider::CALLOUT_YES => 'Charges ' . AgencyServiceProvider::formatRand((float) $amount) . ($note ? ' (' . $note . ')' : ''),
            AgencyServiceProvider::CALLOUT_NO => 'No call-out fee',
            default => 'Not known',
        };
    }
}
