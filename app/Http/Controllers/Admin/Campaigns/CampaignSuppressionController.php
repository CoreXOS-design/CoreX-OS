<?php

namespace App\Http\Controllers\Admin\Campaigns;

use App\Http\Controllers\Controller;
use App\Models\Campaigns\CampaignLead;
use App\Models\Campaigns\CampaignSuppression;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * AT-534 — Campaigns → Suppressed: addresses no CoreX campaign may email. Add by hand, lift (archive), suppress again (restore).
 * Owner-only and DELIBERATELY no permission key. Spec: .ai/specs/campaigns.md §3.6, §7.4.
 */
class CampaignSuppressionController extends Controller
{
    use GuardsCampaigns;

    public function index(Request $request): View
    {
        $this->guard($request);
        [$sortKey, $sortCol, $dir] = $this->sortFrom($request, [
            'email' => 'email', 'reason' => 'reason', 'when' => 'created_at',
        ], 'when', 'desc');

        $query = CampaignSuppression::query()->with(['send' => fn ($q) => $q->with('campaign:id,name')]);
        $lifted = $request->query('archived') === '1';
        if ($lifted) {
            $query->onlyTrashed();
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where('email', 'like', $this->likeTerm(mb_strtolower($q)));
        }
        $reason = (string) $request->query('reason', '');
        if (isset(CampaignSuppression::REASONS[$reason])) {
            $query->where('reason', $reason);
        }
        $this->applyDateRange($query, $request, 'created_at');

        return view('admin.campaigns.suppressions.index', [
            'rows' => $query->orderBy($sortCol, $dir)->orderByDesc('id')->paginate(50)->withQueryString(),
            'sort' => $sortKey,
            'direction' => $dir,
            'lifted' => $lifted,
            'filtered' => $q !== '' || $reason !== '' || $request->filled('from') || $request->filled('to'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guard($request);
        $request->merge(['email' => CampaignLead::normaliseEmail($request->input('email')), 'note' => trim((string) $request->input('note'))]);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:254', Rule::unique('campaign_suppressions', 'email')->whereNull('deleted_at')],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'email.required' => 'Enter an email address.',
            'email.email' => 'That is not a valid email address.',
            'email.unique' => 'That address is already suppressed.',
        ]);

        CampaignSuppression::suppress($data['email'], 'manual', null, ($data['note'] ?? '') !== '' ? $data['note'] : null, $request->user()->id);
        $this->suppressLeads($data['email']);

        return back()->with('success', $data['email'] . ' will not be emailed by any campaign.');
    }

    public function archive(Request $request, int $suppression): RedirectResponse
    {
        $this->guard($request);
        $row = CampaignSuppression::findOrFail($suppression);
        $row->forceFill(['updated_by' => $request->user()->id])->save();
        $row->delete();

        return back()->with('success', 'Suppression lifted for ' . $row->email . '. Its lead stays stopped until you reset it on the list.');
    }

    public function restore(Request $request, int $suppression): RedirectResponse
    {
        $this->guard($request);
        $row = CampaignSuppression::onlyTrashed()->findOrFail($suppression);
        if (CampaignSuppression::where('email', $row->email)->exists()) {
            return back()->with('success', $row->email . ' is already suppressed.');
        }
        $row->restore();
        $this->suppressLeads($row->email);

        return back()->with('success', $row->email . ' is suppressed again.');
    }

    private function suppressLeads(string $email): void
    {
        CampaignLead::where('email', $email)->get()->each(fn ($lead) => $lead->terminate(CampaignLead::STATUS_SUPPRESSED, 'manual'));
    }
}
