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
     * Precedence: BranchSetting (effective branch) -> PerformanceSetting (company) -> defaults.
     */
    private function getPrintSettingsForUser($user): array
    {
        $branchId = $user?->effectiveBranchId() ?? ($user?->branch_id ?? null);

        $defaults = [
            'companyName' => 'Home Finders Coastal',
            'address' => 'The Emporium Shop 5, Shelly Beach, Margate',
            'tel' => '(039) 315 0857',
            'ffc' => '2023116041',
            'logoUrl' => '',
        ];

        $company = [
            'companyName' => (string) PerformanceSetting::get('company_name', $defaults['companyName']),
            'address' => (string) PerformanceSetting::get('company_address', $defaults['address']),
            'tel' => (string) PerformanceSetting::get('company_tel', $defaults['tel']),
            'ffc' => (string) PerformanceSetting::get('company_ffc', $defaults['ffc']),
            'logoUrl' => (string) PerformanceSetting::get('company_logo_url', $defaults['logoUrl']),
        ];

        // Apply branch overrides if user has a branch
        if ($branchId) {
            $company['companyName'] = (string) BranchSetting::getForBranch((int)$branchId, 'company_name', $company['companyName']);
            $company['address']     = (string) BranchSetting::getForBranch((int)$branchId, 'company_address', $company['address']);
            $company['tel']         = (string) BranchSetting::getForBranch((int)$branchId, 'company_tel', $company['tel']);
            $company['ffc']         = (string) BranchSetting::getForBranch((int)$branchId, 'company_ffc', $company['ffc']);
            $company['logoUrl']     = (string) BranchSetting::getForBranch((int)$branchId, 'company_logo_url', $company['logoUrl']);
        }

        // Final fallback safety
        return array_merge($defaults, array_filter($company, fn($v) => $v !== null));
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
