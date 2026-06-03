<?php

namespace App\Services\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CommandTask;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use App\Support\Notifications\AgeFormatter;
use Illuminate\Support\Facades\Schema;

class OverdueSnapshotService
{
    public function __construct(private NotificationPreferenceService $prefs) {}

    /**
     * Read-only snapshot of currently overdue items for a user.
     * Mirrors the watcher predicates but never dispatches.
     */
    public function forUser(User $user): array
    {
        $items = [];

        // Properties — documents missing
        $eff = $this->prefs->effective($user, 'property.documents_missing');
        if ($eff && $eff['enabled'] && $eff['threshold']) {
            $threshold = (int) $eff['threshold'];
            $hasDocs = Schema::hasTable('property_documents');
            $props = Property::where('agent_id', $user->id)
                ->where(function ($q) {
                    $q->whereNull('status')->orWhereNotIn('status', ['sold','withdrawn','expired']);
                })
                ->where('created_at', '<=', now()->subHours($threshold))
                ->limit(50)->get();
            foreach ($props as $p) {
                $hasAny = $hasDocs ? \DB::table('property_documents')->where('property_id', $p->id)->exists() : false;
                if ($hasAny) continue;
                $age      = AgeFormatter::wholeHours($p->created_at);
                $ageHuman = AgeFormatter::ago($p->created_at);
                $label    = trim((string) ($p->address ?? '')) ?: "Property #{$p->id}";
                $items[] = [
                    'event_key' => 'property.documents_missing',
                    'pillar'    => 'property',
                    'subject'   => ['type' => 'property', 'id' => $p->id, 'label' => $label],
                    'age_hours' => $age,
                    'age_human' => $ageHuman,
                    'severity'  => $age > $threshold * 2 ? 'overdue' : 'warning',
                    'action_url'=> "/properties/{$p->id}",
                    'title'     => "{$label} — documents missing",
                    'body'      => $ageHuman
                        ? "Listed {$ageHuman}, no documents on file."
                        : 'No documents on file.',
                    'threshold_hit_at' => $p->created_at?->copy()->addHours($threshold)?->toIso8601String(),
                ];
            }
        }

        // Contacts — FICA missing
        $eff = $this->prefs->effective($user, 'contact.fica_missing');
        if ($eff && $eff['enabled'] && $eff['threshold']) {
            $threshold = (int) $eff['threshold'];
            $contacts = Contact::where('created_by_user_id', $user->id)
                ->where('created_at', '<=', now()->subHours($threshold))
                ->limit(50)->get();
            foreach ($contacts as $c) {
                $hasFica = false;
                try { $hasFica = $c->isFicaCompliant(); } catch (\Throwable $e) {}
                if ($hasFica) continue;
                $name     = trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: ('Contact #' . $c->id);
                $age      = AgeFormatter::wholeHours($c->created_at);
                $ageHuman = AgeFormatter::ago($c->created_at);
                $items[] = [
                    'event_key' => 'contact.fica_missing',
                    'pillar'    => 'contact',
                    'subject'   => ['type' => 'contact', 'id' => $c->id, 'label' => $name],
                    'age_hours' => $age,
                    'age_human' => $ageHuman,
                    'severity'  => 'warning',
                    'action_url'=> "/contacts/{$c->id}",
                    'title'     => "$name — FICA missing",
                    'body'      => $ageHuman
                        ? "Created {$ageHuman} without FICA documents."
                        : 'No FICA documents on file.',
                    'threshold_hit_at' => $c->created_at?->copy()->addHours($threshold)?->toIso8601String(),
                ];
            }
        }

        // Tasks — overdue
        $tasks = CommandTask::where('assigned_to', $user->id)
            ->whereNotIn('status', ['done', 'dismissed'])
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->limit(50)->get();
        foreach ($tasks as $t) {
            $items[] = [
                'event_key' => 'agent.task_due',
                'pillar'    => 'agent',
                'subject'   => ['type' => 'task', 'id' => $t->id, 'label' => $t->title ?? "Task #{$t->id}"],
                'age_hours' => AgeFormatter::wholeHours($t->due_at),
                'severity'  => 'overdue',
                'action_url'=> "/corex#task-{$t->id}",
                'title'     => "Task overdue — " . ($t->title ?? "Task #{$t->id}"),
                'body'      => "Due " . ($t->due_at?->diffForHumans() ?? ''),
                'threshold_hit_at' => $t->due_at?->toIso8601String(),
            ];
        }

        // Events — overdue
        $events = CalendarEvent::where('user_id', $user->id)
            ->where('status', 'pending')
            ->where('starts_at', '<', now())
            ->limit(50)->get();
        foreach ($events as $e) {
            $items[] = [
                'event_key' => 'agent.event_due',
                'pillar'    => 'agent',
                'subject'   => ['type' => 'event', 'id' => $e->id, 'label' => $e->title ?? "Event #{$e->id}"],
                'age_hours' => AgeFormatter::wholeHours($e->starts_at),
                'severity'  => 'overdue',
                'action_url'=> "/corex/command-center/calendar?event={$e->id}",
                'title'     => "Event overdue — " . ($e->title ?? "Event #{$e->id}"),
                'body'      => "Was at " . ($e->starts_at?->format('Y-m-d H:i') ?? ''),
                'threshold_hit_at' => $e->starts_at?->toIso8601String(),
            ];
        }

        $counts = [
            'properties' => count(array_filter($items, fn($i) => $i['pillar'] === 'property')),
            'contacts'   => count(array_filter($items, fn($i) => $i['pillar'] === 'contact')),
            'deals'      => count(array_filter($items, fn($i) => $i['pillar'] === 'deal')),
            'tasks'      => count(array_filter($items, fn($i) => $i['event_key'] === 'agent.task_due')),
            'events'     => count(array_filter($items, fn($i) => $i['event_key'] === 'agent.event_due')),
        ];
        $counts['total'] = array_sum($counts);

        return ['counts' => $counts, 'items' => $items];
    }
}
