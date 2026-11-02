<?php

namespace App\Http\Controllers\Admin\Campaigns;

use Illuminate\Http\Request;

/**
 * AT-534 — shared by the Campaigns controllers: the owner re-check every action makes (on top of the owner_only route middleware,
 * Push Test Lab pattern) and the sort / date-range reading every list screen uses.
 */
trait GuardsCampaigns
{
    private function guard(Request $request): void
    {
        abort_unless($request->user() && $request->user()->isOwnerRole(), 403, 'This area is restricted to System Owners.');
    }

    /** @param array<string, string> $columns sort key => SQL column  @return array{0:string,1:string,2:string} [key, column, direction] */
    private function sortFrom(Request $request, array $columns, string $defaultKey, string $defaultDir): array
    {
        $key = (string) $request->query('sort', $defaultKey);
        if (! isset($columns[$key])) {
            $key = $defaultKey;
        }
        $dir = strtolower((string) $request->query('direction', $key === $defaultKey ? $defaultDir : 'asc')) === 'desc' ? 'desc' : 'asc';

        return [$key, $columns[$key], $dir];
    }

    /** A valid Y-m-d query date, or null (a malformed date is ignored, never an error). */
    private function dateFrom(Request $request, string $name): ?string
    {
        $v = (string) $request->query($name, '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
    }

    private function applyDateRange($query, Request $request, string $column): void
    {
        if ($from = $this->dateFrom($request, 'from')) {
            $query->where($column, '>=', $from . ' 00:00:00');
        }
        if ($to = $this->dateFrom($request, 'to')) {
            $query->where($column, '<=', $to . ' 23:59:59');
        }
    }

    /**
     * The leads table shared by the campaign List tab and the list page (§7.2, §7.3): search name / company / town / email, sort every
     * column (default last name A–Z), filter status + last-sent date range, 50 a page, each lead's sends eager-loaded for the
     * expand-in-place history.
     *
     * @return array{rows: \Illuminate\Contracts\Pagination\LengthAwarePaginator, sort: string, direction: string}
     */
    private function leadsTable(Request $request, int $listId, bool $withArchived = false): array
    {
        $lastSent = '(SELECT MAX(cs.sent_at) FROM campaign_sends cs WHERE cs.campaign_lead_id = campaign_leads.id)';
        $lastStep = '(SELECT MAX(st.step_number) FROM campaign_sends cs2 JOIN campaign_steps st ON st.id = cs2.campaign_step_id WHERE cs2.campaign_lead_id = campaign_leads.id AND cs2.status = \'sent\')';
        [$sortKey, $sortCol, $dir] = $this->sortFrom($request, [
            'first_name' => 'first_name', 'last_name' => 'last_name', 'company' => 'company', 'town' => 'town', 'email' => 'email',
            'phone' => 'phone', 'status' => 'status', 'last_step' => 'last_step', 'last_sent' => 'last_sent_at',
        ], 'last_name', 'asc');

        $query = \App\Models\Campaigns\CampaignLead::query()
            ->where('campaign_list_id', $listId)
            ->select('campaign_leads.*')
            ->selectRaw("{$lastSent} as last_sent_at, {$lastStep} as last_step")
            ->with(['sends' => fn ($q) => $q->with('step:id,step_number')->orderBy('created_at')]);
        if ($withArchived && $request->query('archived') === '1') {
            $query->onlyTrashed();
        }

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = $this->likeTerm($q);
            $query->where(fn ($w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like])
                ->orWhere('company', 'like', $like)->orWhere('town', 'like', $like)->orWhere('email', 'like', $like));
        }
        $status = (string) $request->query('status', '');
        if (in_array($status, \App\Models\Campaigns\CampaignLead::STATUSES, true)) {
            $query->where('status', $status);
        }
        if ($from = $this->dateFrom($request, 'from')) {
            $query->whereRaw("{$lastSent} >= ?", [$from . ' 00:00:00']);
        }
        if ($to = $this->dateFrom($request, 'to')) {
            $query->whereRaw("{$lastSent} <= ?", [$to . ' 23:59:59']);
        }

        $rows = $query->orderBy($sortCol, $dir)->orderBy('campaign_leads.id')->paginate(50)->withQueryString();

        return ['rows' => $rows, 'sort' => $sortKey, 'direction' => $dir];
    }

    private function likeTerm(string $q): string
    {
        return '%' . addcslashes($q, '%_\\') . '%';
    }
}
