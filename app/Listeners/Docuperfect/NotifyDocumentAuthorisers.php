<?php

namespace App\Listeners\Docuperfect;

use App\Events\Docuperfect\TemplateReleased;
use App\Models\Docuperfect\Template;
use App\Models\User;
use App\Notifications\PillarEventNotification;
use App\Services\Docuperfect\Release\AgencyDocumentAuthorisers;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * .ai/specs/esign-template-transfer.md §13.4 — when CoreX releases a document to an agency, the people who may authorise documents there
 * are told (in the app, and by the agency's normal outbound mail), so a document waiting for approval is not found by accident. One
 * recipient failing never stops the others, or the release. Nobody outside the agency is told.
 */
class NotifyDocumentAuthorisers
{
    public function handle(TemplateReleased $event): void
    {
        try {
            $template = Template::withoutGlobalScopes()->find($event->templateId);
            $name = $template?->name ?? 'A document';
            $url = Route::has('docuperfect.template-approvals.show') ? route('docuperfect.template-approvals.show', $event->releaseId, false) : null;

            $recipients = AgencyDocumentAuthorisers::authorisers($event->agencyId())->filter(fn (User $u) => ! empty($u->email));
        } catch (Throwable $e) {
            Log::error('NotifyDocumentAuthorisers: could not work out who to tell', ['release_id' => $event->releaseId, 'error' => $e->getMessage()]);

            return;
        }

        foreach ($recipients as $user) {
            try {
                $user->notify(new PillarEventNotification(
                    eventKey: 'template_release_awaiting_approval',
                    pillar: 'Document',
                    title: 'A document is waiting for your approval',
                    body: '"' . $name . '" has been prepared for your agency. It is not live until you approve it — open it, look at the demo, then approve or decline.',
                    subjectType: Template::class,
                    subjectId: (int) $event->templateId,
                    subjectLabel: $name,
                    actionUrl: $url,
                    severity: 'info',
                    payload: ['release_id' => $event->releaseId, 'agency_id' => $event->agencyId()],
                    channels: ['database', 'mail'],
                    mailer: 'corex',
                ));
            } catch (Throwable $e) {
                Log::error('NotifyDocumentAuthorisers: per-recipient notify failed', ['release_id' => $event->releaseId, 'user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
