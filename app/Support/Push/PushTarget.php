<?php

namespace App\Support\Push;

/**
 * AT-508 - the "open this screen" part of a phone push. A push that only says "Fault reported" opens the app on its home screen; with a
 * `target` + `target_id` the app can open the fault itself. Same vocabulary the tenant / owner pushes already use (RentalClientPushService:
 * fault, work_order, lease_notice), so the mobile app has ONE list of destinations. Records the app has no screen for carry no target.
 */
class PushTarget
{
    /** model class basename (or morph alias) => app destination */
    private const MAP = [
        'RentalFaultReport' => 'fault',
        'RentalWorkOrder' => 'work_order',
        'RentalJobCard' => 'job_card',
        'LeaseNotice' => 'lease_notice',
        'Lease' => 'lease',
        'LeaseBreach' => 'lease',
        'RentalInspection' => 'inspection',
        'RentalApplication' => 'application',
    ];

    /** @return array{target:string,target_id:string}|array{} */
    public static function for(?string $subjectType, int|string|null $subjectId): array
    {
        if (! $subjectType || ! $subjectId) {
            return [];
        }
        $target = self::MAP[class_basename($subjectType)] ?? null;

        return $target ? ['target' => $target, 'target_id' => (string) $subjectId] : [];
    }

    /** Every destination the app may be sent to (listed on the Push Test Lab so the mobile side can check it handles each). */
    public static function destinations(): array
    {
        return array_values(array_unique(self::MAP));
    }

    /** The push a carried (own-class) notification sends: title + body + the same data keys as PillarEventNotification. */
    public static function payload(string $title, string $body, string $eventKey, ?string $subjectType, int|string|null $subjectId, ?string $notificationId, string $severity = 'info'): array
    {
        return [
            'notification' => ['title' => $title, 'body' => $body],
            'data' => array_merge([
                'event_key' => $eventKey,
                'pillar' => 'property',
                'subject_type' => (string) $subjectType,
                'subject_id' => (string) $subjectId,
                'action_url' => '',
                'severity' => $severity,
                'notification_id' => (string) $notificationId,
            ], self::for($subjectType, $subjectId)),
        ];
    }
}
