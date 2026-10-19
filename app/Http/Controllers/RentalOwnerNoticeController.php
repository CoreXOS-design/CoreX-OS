<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\RentalFaultReport;
use App\Models\RentalOwnerHold;
use App\Models\RentalOwnerQuery;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalAuthorisationService;
use App\Services\Rentals\RentalOwnerHoldService;
use App\Support\PortalLink;
use Illuminate\Http\Request;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22.7 - the pages behind "Stop this" and "Query this" in the owner's email. The link is SIGNED (it names this
 * job, this owner and the action). A GET - which a mail scanner may well make - only ASKS and shows; every record is made by the owner's own POST press.
 * Nothing here needs a login: the signature is the proof the link was written for this person.
 */
class RentalOwnerNoticeController extends Controller
{
    /**
     * How long a "Stop this" / "Query this" link lives - the same 60 days as the "I do not agree" link in the fault-settings email
     * (RentalOwnerFaultSettingsMail). After that the owner uses the same two buttons inside their portal. Audit 2026-10-18 A-L5.
     */
    public const LINK_DAYS = 60;

    /** The one place these links are written: signed AND expiring, for the mail and for the page's own forms. */
    public static function signedUrl(string $kind, int $id, int $contact, string $action): string
    {
        return \Illuminate\Support\Facades\URL::temporarySignedRoute('rentals.owner-notice', now()->addDays(self::LINK_DAYS), ['kind' => $kind, 'id' => $id, 'contact' => $contact, 'action' => $action]);
    }

    public function handle(Request $request, string $kind, int $id, int $contact, string $action, RentalOwnerHoldService $holds, RentalAuthorisationService $auth)
    {
        abort_unless(in_array($kind, ['fault', 'work-order'], true) && in_array($action, ['stop', 'query'], true), 404);
        $subject = $kind === 'fault' ? RentalFaultReport::withoutGlobalScopes()->find($id) : RentalWorkOrder::withoutGlobalScopes()->whereNull('deleted_at')->find($id);
        $owner = Contact::withoutGlobalScopes()->find($contact);
        abort_unless($subject && $owner && (int) $owner->agency_id === (int) $subject->agency_id && $auth->ownersFor($subject)->contains('id', $owner->id), 404);

        $notice = $request->isMethod('post') ? (string) $request->input('do', '') : '';
        $error = null;
        $state = null;
        try {
            if ($notice === 'stop') {
                $r = $holds->stop($subject, $owner, (string) $request->input('reason', ''), RentalOwnerHold::VIA_LINK);
                $state = match ($r['result']) { 'stopped' => 'stopped', 'already' => 'held', 'started' => 'started', default => 'closed' };
            } elseif ($notice === 'resume') {
                $holds->resumeByOwner($subject, $owner, RentalOwnerHold::VIA_LINK);
                $state = 'resumed';
            } elseif ($notice === 'ask') {
                $holds->ask($subject, $owner, (string) $request->input('question', ''), RentalOwnerQuery::VIA_LINK);
                $state = 'asked';
            }
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        }

        // what is true NOW decides the page (a GET, a refused press, a second press)
        $hold = $holds->activeFor($subject);
        $block = $holds->stopBlock($subject);
        if ($state === null) {
            $state = $action === 'query' ? 'ask_query' : ($hold ? 'held' : ($block ? $block['code'] : 'ask_stop'));
        }
        [$fault, $wo] = $holds->jobOf($subject);
        $job = $fault ?? $wo;
        $agency = $job->agency;
        $tz = \App\Support\AppDate::agencyTimezone($agency);
        $target = $fault ? ['fault' => $fault->id] : ['wo' => $wo->id];

        return response()->view('rentals.owner-notice', [
            'action' => $action, 'state' => $state, 'error' => $error,
            'agencyName' => \App\Models\Agency::displayNameFor($agency, \App\Support\ClientBrand::NEUTRAL_RENTALS),
            'address' => $job->property?->buildDisplayAddress() ?: ('Property #' . $job->property_id),
            'ownerName' => trim((string) $owner->first_name) ?: 'there',
            'jobTitle' => $fault ? (string) ($fault->ownerVersion()['title'] ?? $fault->title) : (string) $wo->title,
            'hold' => $hold, 'holdLine' => $hold ? $holds->stopLine($hold) : null,
            'queries' => $holds->queriesFor($subject), 'tz' => $tz,
            'queryUrl' => self::signedUrl($kind, $id, $contact, 'query'),
            'stopUrl' => self::signedUrl($kind, $id, $contact, 'stop'),
            'portalUrl' => PortalLink::forContact($owner, PortalLink::VIEW_OWNER, $target),
        ]);
    }
}
