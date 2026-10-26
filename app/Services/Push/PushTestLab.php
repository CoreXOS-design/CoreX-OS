<?php

namespace App\Services\Push;

use App\Models\ClientUser;
use App\Models\CommandCenter\NotificationEventType;
use App\Models\Contact;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\CommandCenter\NotificationPreferenceService;
use App\Services\Push\Contracts\PushTransport;
use App\Services\Rentals\RentalClientPushService;
use App\Support\Push\PushTarget;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * System Developer -> Push Test Lab. Sends a TEST phone notification to one person's registered phones so the owner can check that
 * notifications really reach the app, and lists every notification the app is meant to receive.
 *
 * It goes through the SAME funnel as a real alert (PushNotificationService: token de-dupe, per-phone rate cap, retry, dead-token
 * pruning) and carries the SAME data fields a real alert carries, so the app's tap-to-open handling is exercised too. It deliberately
 * does NOT apply the person's own alert settings - a test must reach the phone whatever they have switched off; the lab shows, beside
 * each type, whether the person WOULD get it for real.
 */
class PushTestLab
{
    public const KIND_STAFF = 'staff';
    public const KIND_CLIENT = 'client';

    /** Staff alert key prefix -> the record the alert is about (for the tap-to-open fields). */
    private const SUBJECT_FOR_PREFIX = [
        'rental_fault_report.' => \App\Models\RentalFaultReport::class,
        'rental_work_order.' => \App\Models\RentalWorkOrder::class,
        'rental_job_card.' => \App\Models\RentalJobCard::class,
        'lease_notice.' => \App\Models\LeaseNotice::class,
        'lease_breach.' => \App\Models\LeaseBreach::class,
        'lease.' => \App\Models\Lease::class,
        'rental_application.' => \App\Models\RentalApplication::class,
        'rental_inspection.due_reminder' => \App\Models\Lease::class,
        'rental_inspection.' => \App\Models\RentalInspection::class,
    ];

    public function __construct(
        private PushNotificationService $push,
        private PushTransport $transport,
        private NotificationPreferenceService $prefs,
        private RentalClientPushService $clientPush,
    ) {}

    /** Is a real push provider connected on this server? When not, a "send" can only count devices. */
    public function transportReady(): bool
    {
        return $this->transport->isOperational();
    }

    /** @return Collection<int, array{id:int,name:string,detail:string,cell:?string,devices:int}> */
    public function searchStaff(string $q): Collection
    {
        $q = trim($q);
        if ($q === '') {
            return collect();
        }
        $like = '%' . $q . '%';
        $digits = preg_replace('/\D+/', '', $q);

        return User::withoutGlobalScopes()->whereNull('deleted_at')
            ->where(function ($w) use ($like, $digits) {
                $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('cell', 'like', $like)->orWhere('phone', 'like', $like);
                if (strlen($digits) >= 4) {
                    $d = '%' . $digits . '%';
                    $w->orWhereRaw("REPLACE(REPLACE(REPLACE(cell,' ',''),'-',''),'+','') like ?", [$d])
                      ->orWhereRaw("REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+','') like ?", [$d]);
                }
            })
            ->orderBy('name')->limit(15)->get(['id', 'name', 'email', 'cell', 'phone', 'agency_id', 'role'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => (string) $u->name,
                'detail' => trim(($u->email ?: '') . ($u->role ? ' · ' . $u->role : '')),
                'cell' => $u->cell ?: $u->phone,
                'devices' => DeviceToken::where('user_id', $u->id)->count(),
            ]);
    }

    /** Tenants and owners are portal logins; they are found through the contact the agency holds for them. @return Collection<int, array{id:int,name:string,detail:string,cell:?string,devices:int}> */
    public function searchClients(string $q): Collection
    {
        $q = trim($q);
        if ($q === '') {
            return collect();
        }
        $like = '%' . $q . '%';
        $digits = preg_replace('/\D+/', '', $q);

        $rows = Contact::withoutGlobalScopes()->whereNull('deleted_at')->whereNotNull('client_user_id')
            ->where(function ($w) use ($like, $digits) {
                $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)
                  ->orWhereRaw("CONCAT(first_name,' ',last_name) like ?", [$like]);
                if (strlen($digits) >= 4) {
                    $w->orWhereRaw("REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+','') like ?", ['%' . $digits . '%']);
                }
            })
            ->orderBy('first_name')->limit(30)->get(['id', 'client_user_id', 'first_name', 'last_name', 'phone', 'email']);

        return $rows->unique('client_user_id')->take(15)->map(fn (Contact $c) => [
            'id' => (int) $c->client_user_id,
            'name' => trim($c->first_name . ' ' . $c->last_name),
            'detail' => (string) $c->email,
            'cell' => $c->phone,
            'devices' => DeviceToken::where('client_user_id', $c->client_user_id)->count(),
        ])->values();
    }

    public function staffName(int $id): ?string
    {
        return User::withoutGlobalScopes()->whereKey($id)->value('name');
    }

    public function clientName(int $id): ?string
    {
        $c = Contact::withoutGlobalScopes()->where('client_user_id', $id)->orderBy('id')->first(['first_name', 'last_name']);

        return $c ? trim($c->first_name . ' ' . $c->last_name) : (ClientUser::withoutGlobalScopes()->whereKey($id)->value('email') ?: null);
    }

    /** @return Collection<int, DeviceToken> the person's registered phones, newest first */
    public function devices(string $kind, int $id): Collection
    {
        return DeviceToken::query()
            ->where($kind === self::KIND_CLIENT ? 'client_user_id' : 'user_id', $id)
            ->orderByDesc('last_seen_at')->orderByDesc('id')->get(['id', 'platform', 'token', 'app_version', 'last_seen_at', 'created_at']);
    }

    /**
     * Every alert type that reaches an AGENT'S phone, grouped; with, for the selected agent, whether they would get it for real.
     *
     * @return array<string, list<array{key:string,label:string,description:?string,target:?string,real:?bool}>>
     */
    public function staffCatalogue(?User $forUser = null): array
    {
        $out = [];
        foreach (NotificationEventType::where('supports_push', true)->orderBy('group_label')->orderBy('sort_order')->get() as $t) {
            $real = null;
            if ($forUser) {
                $eff = $this->prefs->effective($forUser, $t->key);
                $real = (bool) ($eff && $eff['enabled'] && $eff['channel_push']);
            }
            $out[$t->group_label ?: 'Other'][] = [
                'key' => $t->key, 'label' => $t->label, 'description' => $t->description,
                'target' => PushTarget::for($this->subjectFor($t->key), 1)['target'] ?? null, 'real' => $real,
            ];
        }

        return $out;
    }

    /** Alert types that are in-app / email only on purpose (so the full picture is on one screen). @return Collection<int, NotificationEventType> */
    public function staffNotOnPhone(): Collection
    {
        return NotificationEventType::where('supports_push', false)->orderBy('group_label')->orderBy('sort_order')->get(['key', 'label', 'group_label']);
    }

    /** What a tenant or owner's phone can be sent (config/rental-push.php). @return array<string, array{audience:string,mandatory:bool,label:string,description:string}> */
    public function clientCatalogue(): array
    {
        return $this->clientPush->catalogue();
    }

    /**
     * Send one test push. $what = "event:<key>" (an agent alert), "category:<key>" (a tenant / owner category) or "custom".
     *
     * @return array{ok:bool, level:string, message:string, devices:int}
     */
    public function send(string $kind, int $id, string $what, ?string $recordId, ?string $title, ?string $body): array
    {
        $payload = $this->payloadFor($kind, $what, $recordId, $title, $body);
        if ($payload === null) {
            return ['ok' => false, 'level' => 'error', 'message' => 'That notification type is not one the app receives.', 'devices' => 0];
        }

        $devices = $this->devices($kind, $id)->count();
        if ($devices === 0) {
            return ['ok' => false, 'level' => 'error', 'devices' => 0,
                'message' => 'This person has no phone registered, so nothing can be sent. They need to open the CoreX app on their phone and sign in once (that registers the phone).'];
        }
        if (! $this->transportReady()) {
            return ['ok' => false, 'level' => 'error', 'devices' => $devices,
                'message' => "This server is not connected to the phone-notification provider (Firebase), so nothing was sent. {$devices} phone(s) are registered."];
        }

        // A fresh key every time: a test must never be swallowed as "already sent".
        $key = 'push-test:' . Str::uuid();
        $summary = $kind === self::KIND_CLIENT
            ? $this->push->sendToClientUserIds([$id], $key, $payload)
            : $this->push->sendToUserIds([$id], $key, $payload);

        if ($summary->rateLimited > 0 && $summary->sent === 0) {
            return ['ok' => false, 'level' => 'warning', 'devices' => $devices,
                'message' => 'Not sent: that phone has already received its limit for this minute (a safety limit that stops notification floods). Wait a minute and try again.'];
        }
        if ($summary->sent === 0) {
            return ['ok' => false, 'level' => 'error', 'devices' => $devices,
                'message' => "The provider did not accept the message for any of the {$devices} registered phone(s). The phone's registration is probably out of date - sign out and in again on the phone, then retry. (Out-of-date registrations are removed automatically.)"];
        }

        return ['ok' => true, 'level' => 'success', 'devices' => $devices,
            'message' => "Sent to {$summary->sent} of {$devices} registered phone(s). If nothing appears on the phone within a minute, the app's notification permission or channel is the place to look."];
    }

    /** @return array{notification:array{title:string,body:string},data:array<string,string>}|null */
    private function payloadFor(string $kind, string $what, ?string $recordId, ?string $title, ?string $body): ?array
    {
        $recordId = $recordId !== null && ctype_digit($recordId) && (int) $recordId > 0 ? $recordId : '0';
        $nid = (string) Str::uuid();

        if ($what === 'custom') {
            $t = trim((string) $title) !== '' ? trim((string) $title) : 'CoreX test notification';
            $b = trim((string) $body) !== '' ? trim((string) $body) : 'If you can read this, notifications are reaching this phone.';

            return ['notification' => ['title' => '[TEST] ' . Str::limit($t, 80, ''), 'body' => Str::limit($b, 200, '')],
                'data' => ['event_key' => 'test.custom', 'test' => '1', 'notification_id' => $nid]];
        }

        if ($kind === self::KIND_STAFF && str_starts_with($what, 'event:')) {
            $type = NotificationEventType::where('key', substr($what, 6))->where('supports_push', true)->first();
            if (! $type) {
                return null;
            }
            $subject = $this->subjectFor($type->key);

            return [
                'notification' => ['title' => '[TEST] ' . $type->label, 'body' => Str::limit((string) ($type->description ?: $type->label), 200, '')],
                'data' => array_merge([
                    'event_key' => $type->key, 'pillar' => (string) $type->pillar, 'subject_type' => (string) $subject, 'subject_id' => $recordId,
                    'action_url' => '', 'severity' => 'info', 'notification_id' => $nid, 'test' => '1',
                ], PushTarget::for($subject, $recordId)),
            ];
        }

        if ($kind === self::KIND_CLIENT && str_starts_with($what, 'category:')) {
            $key = substr($what, 9);
            $c = $this->clientCatalogue()[$key] ?? null;
            if (! $c) {
                return null;
            }

            return [
                'notification' => ['title' => '[TEST] ' . $c['label'], 'body' => $c['description']],
                'data' => ['type' => 'rental', 'category' => $key, 'mandatory' => $c['mandatory'] ? '1' : '0', 'audience' => $c['audience'] === 'both' ? 'tenant' : $c['audience'],
                    'subject_type' => $this->clientSubjectFor($key), 'subject_id' => $recordId, 'test' => '1'],
            ];
        }

        return null;
    }

    private function subjectFor(string $eventKey): ?string
    {
        foreach (self::SUBJECT_FOR_PREFIX as $prefix => $class) {
            if (str_starts_with($eventKey, $prefix)) {
                return $class;
            }
        }

        return null;
    }

    /** The destination word a tenant / owner push carries (same vocabulary as SendRentalClientPush). */
    private function clientSubjectFor(string $category): string
    {
        return match ($category) {
            'decision_needed', 'work_check_requested', 'work_finished', 'appointment', 'work_order_update' => 'work_order',
            'fault_status' => 'fault',
            'notice_outcome' => 'lease_notice',
            'inspection_ready' => 'inspection',
            default => '',
        };
    }
}
