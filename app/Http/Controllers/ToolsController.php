<?php

namespace App\Http\Controllers;

use App\Models\ToolHistoryEntry;
use App\Models\BranchSetting;
use App\Models\PerformanceSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ToolsController extends Controller
{
    public function commission()
    {
        $user = Auth::user();
        $printSettings = $this->getPrintSettingsForUser($user);

        return view('tools.tools', [
            'defaultTab' => 'commission',
            'printSettings' => $printSettings,
        ]);
    }

    public function cma()
    {
        $user = Auth::user();
        $printSettings = $this->getPrintSettingsForUser($user);

        return view('tools.tools', [
            'defaultTab' => 'cma',
            'printSettings' => $printSettings,
        ]);
    }


    /**
     * Print settings are server-controlled.
     * Precedence: BranchSetting (effective branch) -> PerformanceSetting (company)
     * -> Agency model fields -> defaults.
     *
     * 2026-05-25 — fall back to the user's Agency model fields (name, address,
     * phone, ffc_no, logo_path) when the BranchSetting / PerformanceSetting
     * entry is empty. Without this fallback the CMA cert + Tools page renders
     * with no logo because `company_logo_url` was never seeded in
     * performance_settings.
     */
    private function getPrintSettingsForUser($user): array
    {
        $branchId = $user?->effectiveBranchId() ?? ($user?->branch_id ?? null);
        $agency   = $user?->agency;

        $defaults = [
            'companyName' => 'Home Finders Coastal',
            'address' => 'The Emporium Shop 5, Shelly Beach, Margate',
            'tel' => '(039) 315 0857',
            'ffc' => '2023116041',
            'logoUrl' => '',
        ];

        // Agency-level fallback values from the user's Agency model.
        $agencyLogoUrl = ($agency && $agency->logo_path)
            ? asset('storage/' . $agency->logo_path)
            : '';
        $agencyDefaults = [
            'companyName' => $agency?->name    ?: $defaults['companyName'],
            'address'     => $agency?->address ?: $defaults['address'],
            'tel'         => $agency?->phone   ?: $defaults['tel'],
            'ffc'         => $agency?->ffc_no  ?: $defaults['ffc'],
            'logoUrl'     => $agencyLogoUrl    ?: $defaults['logoUrl'],
        ];

        $company = [
            'companyName' => (string) PerformanceSetting::get('company_name',     $agencyDefaults['companyName']),
            'address'     => (string) PerformanceSetting::get('company_address',  $agencyDefaults['address']),
            'tel'         => (string) PerformanceSetting::get('company_tel',      $agencyDefaults['tel']),
            'ffc'         => (string) PerformanceSetting::get('company_ffc',      $agencyDefaults['ffc']),
            'logoUrl'     => (string) PerformanceSetting::get('company_logo_url', $agencyDefaults['logoUrl']),
        ];

        // Apply branch overrides if user has a branch — but only when the
        // BranchSetting value is non-empty. An unset branch setting must NOT
        // overwrite the upstream agency-level value with an empty string.
        if ($branchId) {
            $maybeBranch = function (string $key, string $fallback) use ($branchId): string {
                $v = (string) BranchSetting::getForBranch((int) $branchId, $key, $fallback);
                return $v !== '' ? $v : $fallback;
            };
            $company['companyName'] = $maybeBranch('company_name',     $company['companyName']);
            $company['address']     = $maybeBranch('company_address',  $company['address']);
            $company['tel']         = $maybeBranch('company_tel',      $company['tel']);
            $company['ffc']         = $maybeBranch('company_ffc',      $company['ffc']);
            $company['logoUrl']     = $maybeBranch('company_logo_url', $company['logoUrl']);
        }

        // Logo exception: the Agency model's `logo_path` is the canonical
        // logo source used by the email signature, settings page, onboarding
        // portal, etc. When the agency has a logo, force it to win over any
        // PerformanceSetting / BranchSetting `company_logo_url` (which may
        // hold a stale upload path from before the agency model carried it).
        if ($agencyLogoUrl !== '') {
            $company['logoUrl'] = $agencyLogoUrl;
        }

        return array_merge($defaults, array_filter($company, fn($v) => $v !== null && $v !== ''));
    }

    // ===== Tools History API =====

    public function historyIndex(Request $request)
    {
        $user = Auth::user();

        $items = ToolHistoryEntry::query()
            ->where('user_id', $user->id)
            ->orderByDesc('occurred_at')
            ->limit(250)
            ->get([
                'id',
                'ref',
                'type',
                'occurred_at',
                'property',
                'agent_name',
                'value',
                'branch_id',
            ]);

        return response()->json([
            'ok' => true,
            'items' => $items,
        ]);
    }

    public function historyShow(int $id)
    {
        $user = Auth::user();

        $item = ToolHistoryEntry::query()
            ->where('user_id', $user->id)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'ok' => true,
            'item' => $item,
        ]);
    }

    public function historyStore(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'type' => ['required', 'string', 'in:CALC,CMA'],
            'property' => ['required', 'string', 'max:255'],
            'value' => ['required', 'numeric'],
            'payload' => ['required', 'array'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $branchId = $user?->effectiveBranchId() ?? ($user?->branch_id ?? null);

        $occurredAt = isset($data['occurred_at']) && $data['occurred_at']
            ? now()->parse($data['occurred_at'])
            : now();

        $ref = $this->generateToolRef($data['type']);

        $item = ToolHistoryEntry::create([
            'user_id' => $user->id,
            'branch_id' => $branchId,
            'type' => $data['type'],
            'ref' => $ref,
            'occurred_at' => $occurredAt,
            'property' => $data['property'],
            'value' => $data['value'],
            'agent_name' => $user->name ?? 'User',
            'payload' => $data['payload'],
        ]);

        return response()->json([
            'ok' => true,
            'item' => $item,
        ]);
    }

    public function historyDestroy(int $id)
    {
        $user = Auth::user();

        $item = ToolHistoryEntry::query()
            ->where('user_id', $user->id)
            ->where('id', $id)
            ->firstOrFail();

        $item->delete();

        return response()->json(['ok' => true]);
    }

    private function generateToolRef(string $type): string
    {
        $year = now()->format('Y');
        $prefix = "HF-{$year}-{$type}-";

        // Find max existing numeric suffix for this year+type.
        $maxRef = ToolHistoryEntry::query()
            ->where('ref', 'like', $prefix . '%')
            ->max('ref');

        $next = 1;
        if ($maxRef) {
            $tail = substr($maxRef, strlen($prefix));
            if (ctype_digit($tail)) {
                    $next = intval($tail) + 1;
                    if ($next < 1) {
                        $next = 1;
                    }
                }
        }

        return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }
}
