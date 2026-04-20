<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Compliance\UserComplianceOverride;
use App\Models\User;
use Illuminate\Http\Request;

class UserComplianceOverrideController extends Controller
{
    public function store(Request $request, User $user)
    {
        abort_unless(auth()->user()->hasPermission('manage_user_compliance'), 403);

        $validated = $request->validate([
            'compliance_item' => ['required', 'string', 'max:50'],
            'override_type'   => ['required', 'string', 'in:exempt,waived,not_applicable'],
            'reason'          => ['required', 'string', 'min:15', 'max:2000'],
            'expires_at'      => ['nullable', 'date'],
        ]);

        // Revoke any existing active override for this item
        $existing = UserComplianceOverride::where('user_id', $user->id)
            ->where('compliance_item', $validated['compliance_item'])
            ->active()
            ->get();

        foreach ($existing as $old) {
            $old->update([
                'revoked_by'     => auth()->id(),
                'revoked_at'     => now(),
                'revoke_reason'  => 'Replaced by new override',
            ]);
        }

        $override = UserComplianceOverride::create([
            'user_id'         => $user->id,
            'agency_id'       => $user->agency_id,
            'compliance_item' => $validated['compliance_item'],
            'override_type'   => $validated['override_type'],
            'reason'          => $validated['reason'],
            'created_by'      => auth()->id(),
            'expires_at'      => $validated['expires_at'] ?? null,
        ]);

        logger()->info('Compliance override created', [
            'override_id' => $override->id,
            'target_user_id' => $user->id,
            'target_user_name' => $user->name,
            'compliance_item' => $validated['compliance_item'],
            'override_type' => $validated['override_type'],
            'reason' => $validated['reason'],
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('admin.users.edit', $user)
            ->with('success', ucfirst(str_replace('_', ' ', $validated['override_type'])) . ' override set for ' . $user->name . '.');
    }

    public function revoke(Request $request, UserComplianceOverride $override)
    {
        abort_unless(auth()->user()->hasPermission('manage_user_compliance'), 403);

        $validated = $request->validate([
            'revoke_reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $override->update([
            'revoked_by'    => auth()->id(),
            'revoked_at'    => now(),
            'revoke_reason' => $validated['revoke_reason'],
        ]);

        logger()->info('Compliance override revoked', [
            'override_id' => $override->id,
            'target_user_id' => $override->user_id,
            'revoked_by' => auth()->id(),
            'revoke_reason' => $validated['revoke_reason'],
        ]);

        return redirect()->route('admin.users.edit', $override->user_id)
            ->with('success', 'Override revoked.');
    }
}
