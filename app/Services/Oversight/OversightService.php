<?php

namespace App\Services\Oversight;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use App\Models\UserOversightPreference;
use App\Models\CommandCenter\CommandTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates outstanding items across the 7 oversight categories
 * for the agents in the current manager's scope.
 */
class OversightService
{
    /**
     * Resolve the user IDs the given manager is allowed to oversee.
     */
    public function agentsInScope(User $manager): Collection
    {
        $role = $manager->roleModel();
        $scope = $role->oversight_scope ?? 'branch';

        $query = User::query()->where('id', '!=', $manager->id);

        if ($scope === 'branch') {
            $branchId = $manager->branch_id;
            if (!$branchId) {
                return collect();
            }
            $query->where('branch_id', $branchId);
        }

        return $query->get(['id', 'name', 'email', 'branch_id']);
    }

    /**
     * Build the full oversight feed for a manager.
     * Returns a flat collection of rows ready for the blade view.
     *
     * @return Collection<int, array{
     *   category: string, agent_id: int, agent_name: string,
     *   subject_type: ?string, subject_id: ?int, summary: string,
     *   age_hours: int, severity: string, deep_link: ?string
     * }>
     */
    public function feed(User $manager, ?string $categoryFilter = null, ?int $agentFilter = null): Collection
    {
        $agents = $this->agentsInScope($manager);
        if ($agents->isEmpty()) {
            return collect();
        }
        if ($agentFilter) {
            $agents = $agents->where('id', $agentFilter);
        }
        $agentIds = $agents->pluck('id')->all();
        $agentMap = $agents->keyBy('id');

        $prefs = UserOversightPreference::query()
            ->where('user_id', $manager->id)
            ->get()
            ->keyBy('category');

        $rows = collect();

        $cats = $categoryFilter ? [$categoryFilter] : UserOversightPreference::CATEGORIES;

        foreach ($cats as $category) {
            $threshold = $prefs[$category]->threshold_hours
                ?? UserOversightPreference::DEFAULTS[$category]['threshold_hours']
                ?? 24;

            $rows = $rows->merge($this->signal($category, $agentIds, $agentMap, $threshold));
        }

        return $rows->sortByDesc('age_hours')->values();
    }

    protected function signal(string $category, array $agentIds, Collection $agentMap, int $thresholdHours): Collection
    {
        return match ($category) {
            'ignored_notifications' => $this->ignoredNotifications($agentIds, $agentMap, $thresholdHours),
            'deals_near_expiry'     => $this->dealsNearExpiry($agentIds, $agentMap, $thresholdHours),
            'expiring_mandates'     => $this->expiringMandates($agentIds, $agentMap, $thresholdHours),
            'stale_listings'        => $this->staleListings($agentIds, $agentMap, $thresholdHours),
            'overdue_tasks'         => $this->overdueTasks($agentIds, $agentMap),
            'expiring_ffcs'         => $this->expiringFfcs($agentIds, $agentMap, $thresholdHours),
            'stale_leads'           => $this->staleLeads($agentIds, $agentMap, $thresholdHours),
            default                 => collect(),
        };
    }

    protected function ignoredNotifications(array $agentIds, Collection $agentMap, int $hours): Collection
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('notifications')) {
            return collect();
        }

        $cutoff = Carbon::now()->subHours($hours);

        $rows = DB::table('notifications')
            ->whereIn('notifiable_id', $agentIds)
            ->where('notifiable_type', User::class)
            ->whereNull('read_at')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at')
            ->limit(200)
            ->get();

        return $rows->map(function ($n) use ($agentMap, $hours) {
            $agent = $agentMap[$n->notifiable_id] ?? null;
            $age = Carbon::parse($n->created_at)->diffInHours(now());
            return [
                'category'     => 'ignored_notifications',
                'agent_id'     => (int) $n->notifiable_id,
                'agent_name'   => $agent->name ?? 'Unknown',
                'subject_type' => 'notification',
                'subject_id'   => $n->id,
                'summary'      => 'Unread notification: ' . \Illuminate\Support\Str::limit(json_decode($n->data ?? '{}')->message ?? 'Notification', 80),
                'age_hours'    => $age,
                'severity'     => $age > $hours * 2 ? 'high' : 'medium',
                'deep_link'    => null,
            ];
        });
    }

    protected function dealsNearExpiry(array $agentIds, Collection $agentMap, int $hours): Collection
    {
        $thresholdDate = Carbon::now()->addHours($hours);

        if (!\Illuminate\Support\Facades\Schema::hasTable('deal_user')) {
            return collect();
        }

        $deals = Deal::query()
            ->join('deal_user', 'deal_user.deal_id', '=', 'deals.id')
            ->whereIn('deal_user.user_id', $agentIds)
            ->whereNull('deals.registration_date')
            ->whereNotNull('deals.deal_date')
            ->where('deals.deal_date', '<=', $thresholdDate)
            ->where('deals.deal_date', '>=', Carbon::now()->subYears(1))
            ->select('deals.*', 'deal_user.user_id as assigned_user_id')
            ->limit(200)
            ->get();

        return $deals->map(function ($deal) use ($agentMap) {
            $agent = $agentMap[$deal->assigned_user_id] ?? null;
            $age = $deal->updated_at ? Carbon::parse($deal->updated_at)->diffInHours(now()) : 0;
            return [
                'category'     => 'deals_near_expiry',
                'agent_id'     => (int) $deal->assigned_user_id,
                'agent_name'   => $agent->name ?? 'Unknown',
                'subject_type' => Deal::class,
                'subject_id'   => $deal->id,
                'summary'      => sprintf('Deal #%s — %s, no registration', $deal->deal_no, $deal->property_address ?? ''),
                'age_hours'    => $age,
                'severity'     => 'high',
                'deep_link'    => route('corex.dashboard') . '#deal-' . $deal->id,
            ];
        });
    }

    protected function expiringMandates(array $agentIds, Collection $agentMap, int $hours): Collection
    {
        $threshold = Carbon::now()->addHours($hours);

        $properties = Property::query()
            ->whereIn('agent_id', $agentIds)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', $threshold)
            ->where('expiry_date', '>=', Carbon::now()->subDays(7))
            ->limit(200)
            ->get();

        return $properties->map(function ($p) use ($agentMap) {
            $agent = $agentMap[$p->agent_id] ?? null;
            $age = Carbon::parse($p->expiry_date)->diffInHours(now());
            return [
                'category'     => 'expiring_mandates',
                'agent_id'     => (int) $p->agent_id,
                'agent_name'   => $agent->name ?? 'Unknown',
                'subject_type' => Property::class,
                'subject_id'   => $p->id,
                'summary'      => sprintf('Mandate expires %s — %s', Carbon::parse($p->expiry_date)->toDateString(), $p->mandate_type ?? ''),
                'age_hours'    => $age,
                'severity'     => 'high',
                'deep_link'    => null,
            ];
        });
    }

    protected function staleListings(array $agentIds, Collection $agentMap, int $hours): Collection
    {
        $cutoff = Carbon::now()->subHours($hours);

        $properties = Property::query()
            ->whereIn('agent_id', $agentIds)
            ->where('updated_at', '<=', $cutoff)
            ->whereNull('expiry_date')
            ->orWhere(function ($q) use ($cutoff) {
                $q->where('expiry_date', '>', Carbon::now()->addDays(30))
                  ->where('updated_at', '<=', $cutoff);
            })
            ->limit(200)
            ->get();

        return $properties->map(function ($p) use ($agentMap) {
            $agent = $agentMap[$p->agent_id] ?? null;
            $age = Carbon::parse($p->updated_at)->diffInHours(now());
            return [
                'category'     => 'stale_listings',
                'agent_id'     => (int) $p->agent_id,
                'agent_name'   => $agent->name ?? 'Unknown',
                'subject_type' => Property::class,
                'subject_id'   => $p->id,
                'summary'      => sprintf('Listing untouched for %dh — %s', $age, $p->address ?? $p->title ?? '#' . $p->id),
                'age_hours'    => $age,
                'severity'     => 'medium',
                'deep_link'    => null,
            ];
        });
    }

    protected function overdueTasks(array $agentIds, Collection $agentMap): Collection
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('command_tasks')) {
            return collect();
        }

        $tasks = CommandTask::query()
            ->whereIn('assigned_user_id', $agentIds)
            ->whereNotIn('status', ['done', 'dismissed'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', Carbon::now())
            ->limit(200)
            ->get();

        return $tasks->map(function ($t) use ($agentMap) {
            $agent = $agentMap[$t->assigned_user_id] ?? null;
            $age = Carbon::parse($t->due_date)->diffInHours(now());
            return [
                'category'     => 'overdue_tasks',
                'agent_id'     => (int) $t->assigned_user_id,
                'agent_name'   => $agent->name ?? 'Unknown',
                'subject_type' => CommandTask::class,
                'subject_id'   => $t->id,
                'summary'      => sprintf('Task overdue %dh — %s', $age, \Illuminate\Support\Str::limit($t->title ?? $t->name ?? '#' . $t->id, 80)),
                'age_hours'    => $age,
                'severity'     => $age > 72 ? 'high' : 'medium',
                'deep_link'    => null,
            ];
        });
    }

    protected function expiringFfcs(array $agentIds, Collection $agentMap, int $hours): Collection
    {
        $threshold = Carbon::now()->addHours($hours);

        $users = User::query()
            ->whereIn('id', $agentIds)
            ->whereNotNull('ffc_expiry_date')
            ->where('ffc_expiry_date', '<=', $threshold)
            ->get();

        return $users->map(function ($u) {
            $age = Carbon::parse($u->ffc_expiry_date)->diffInHours(now());
            return [
                'category'     => 'expiring_ffcs',
                'agent_id'     => (int) $u->id,
                'agent_name'   => $u->name,
                'subject_type' => User::class,
                'subject_id'   => $u->id,
                'summary'      => sprintf('FFC expires %s', Carbon::parse($u->ffc_expiry_date)->toDateString()),
                'age_hours'    => $age,
                'severity'     => 'high',
                'deep_link'    => null,
            ];
        });
    }

    protected function staleLeads(array $agentIds, Collection $agentMap, int $hours): Collection
    {
        $cutoff = Carbon::now()->subHours($hours);

        $contacts = Contact::query()
            ->whereIn('created_by_user_id', $agentIds)
            ->where('updated_at', '<=', $cutoff)
            ->limit(200)
            ->get();

        return $contacts->map(function ($c) use ($agentMap) {
            $agent = $agentMap[$c->created_by_user_id] ?? null;
            $age = Carbon::parse($c->updated_at)->diffInHours(now());
            return [
                'category'     => 'stale_leads',
                'agent_id'     => (int) $c->created_by_user_id,
                'agent_name'   => $agent->name ?? 'Unknown',
                'subject_type' => Contact::class,
                'subject_id'   => $c->id,
                'summary'      => sprintf('Lead untouched for %dh — %s', $age, trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''))),
                'age_hours'    => $age,
                'severity'     => 'medium',
                'deep_link'    => null,
            ];
        });
    }
}
