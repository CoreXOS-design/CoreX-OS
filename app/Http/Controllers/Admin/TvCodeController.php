<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\TvAccessCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TvCodeController extends Controller
{
    /**
     * Generate a new TV access code for a specified branch (admin).
     */
    public function generate(Request $request)
    {
        $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        // Branch::findOrFail is already tenant-safe: Branch uses
        // BelongsToAgency, so its global scope 404s a branch belonging to a
        // different agency for any non-owner caller. Before this, the raw
        // exists:branches,id rule above accepted ANY branch id — any admin
        // with manage_tv_messages could mint a live public TV code for
        // another agency's branch. Finding C1,
        // .ai/audits/cross-agency-isolation-audit-2026-08-20.md.
        $branch = Branch::findOrFail((int) $request->branch_id);
        $branchId = $branch->id;
        $user = Auth::user();

        // Deactivate all existing active codes for this branch
        TvAccessCode::where('branch_id', $branchId)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        // Generate new code
        TvAccessCode::create([
            'branch_id'  => $branchId,
            'code'       => TvAccessCode::generateUniqueCode(),
            'created_by' => $user->id,
            'is_active'  => true,
        ]);

        return back()->with('status', 'New TV code generated.');
    }

    /**
     * Revoke a TV access code by ID (admin).
     */
    public function revoke(Request $request)
    {
        $request->validate([
            'code_id' => ['required', 'integer', 'exists:tv_access_codes,id'],
        ]);

        // TvAccessCode now uses BelongsToAgency (finding C1) — findOrFail
        // 404s for a code belonging to a different agency instead of
        // revoking it, since the exists:tv_access_codes,id rule above is an
        // unscoped raw existence check.
        $code = TvAccessCode::findOrFail($request->code_id);
        $code->update(['is_active' => false]);

        return back()->with('status', 'TV code revoked.');
    }

    /**
     * Generate a new company-wide TV access code (branch_id = NULL).
     */
    public function generateCompany()
    {
        $user = Auth::user();

        // Deactivate all existing active company codes
        TvAccessCode::forCompany()
            ->where('is_active', true)
            ->update(['is_active' => false]);

        TvAccessCode::create([
            'branch_id'  => null,
            'code'       => TvAccessCode::generateUniqueCode(),
            'created_by' => $user->id,
            'is_active'  => true,
        ]);

        return back()->with('status', 'New company TV code generated.');
    }

    /**
     * Revoke the active company TV code.
     */
    public function revokeCompany()
    {
        $deactivated = TvAccessCode::forCompany()
            ->where('is_active', true)
            ->update(['is_active' => false]);

        if ($deactivated > 0) {
            return back()->with('status', 'Company TV code revoked.');
        }

        return back()->with('status', 'No active company TV code to revoke.');
    }
}
