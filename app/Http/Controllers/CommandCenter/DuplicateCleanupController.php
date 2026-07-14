<?php

namespace App\Http\Controllers\CommandCenter;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DuplicateCleanupController extends Controller
{
    public function index(Request $request)
    {
        $agencyId = (int) (auth()->user()?->effectiveAgencyId() ?: 0);   // AT-253 Rule 17

        $clusters = DB::table('contact_duplicate_clusters')
            ->where('agency_id', $agencyId)
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->paginate(20);

        // Hydrate contact data for display
        $allContactIds = collect();
        foreach ($clusters as $cluster) {
            $ids = json_decode($cluster->contact_ids, true) ?? [];
            $allContactIds = $allContactIds->merge($ids);
        }

        $contacts = Contact::withoutGlobalScopes()
            ->whereIn('id', $allContactIds->unique())
            ->with('createdBy')
            ->get()
            ->keyBy('id');

        return view('command-center.admin.duplicate-cleanup', [
            'clusters' => $clusters,
            'contacts' => $contacts,
        ]);
    }

    public function dismiss(Request $request, int $clusterId)
    {
        DB::table('contact_duplicate_clusters')
            ->where('id', $clusterId)
            ->update([
                'status' => 'dismissed',
                'reviewed_by_user_id' => auth()->id(),
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

        return back()->with('success', 'Cluster dismissed (marked as not duplicate).');
    }
}
