<?php

namespace App\Services\AttorneyPortal;

use App\Mail\AttorneyPortalNewDocumentsMail;
use App\Models\Agency;
use App\Models\AttorneyPortalAccess;
use App\Models\AttorneyPortalSetting;
use App\Models\Scopes\AgencyScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * .ai/specs/attorney-portal.md 15 - the "new documents" digest. For each live attorney access of an agency that has it on, and each
 * deal they currently see: if documents in THEIR set were filed after the last time they were told (or, the first time, after their access
 * was created), send ONE note for that deal - at most one per deal per calendar day - naming the kinds of document, with a signed link into
 * the portal. Never an attachment. Run by `attorney-portal:notify-new-documents` (scheduled daily).
 */
class AttorneyPortalNotifier
{
    public function __construct(
        private AttorneyPortalAccessService $access,
        private AttorneyPortalScope $scope,
        private AttorneyPortalDocuments $documents,
    ) {}

    /** @return array{sent:int, considered:int} */
    public function run(?int $onlyAgencyId = null, bool $dryRun = false): array
    {
        $sent = 0;
        $considered = 0;

        $accesses = AttorneyPortalAccess::withoutGlobalScope(AgencyScope::class)
            ->whereNull('revoked_at')->where('expires_at', '>', now())
            ->when($onlyAgencyId, fn ($q) => $q->where('agency_id', $onlyAgencyId))
            ->with(['supplierContact', 'firm'])->get();

        foreach ($accesses->groupBy('agency_id') as $agencyId => $group) {
            $settings = AttorneyPortalSetting::forAgency((int) $agencyId);
            if (! $settings->isEnabled() || $settings->notifyMode() !== AttorneyPortalSetting::NOTIFY_DAILY) {
                continue;
            }
            $agency = Agency::withoutGlobalScopes()->find($agencyId);

            foreach ($group as $access) {
                if (! $this->access->isUsable($access)) {
                    continue;
                }
                foreach ($this->scope->visibleDeals($access) as $deal) {
                    $considered++;
                    $notice = DB::table('attorney_portal_notices')->where('access_id', $access->id)->where('deal_id', $deal->id)->first();
                    if ($notice && $notice->last_sent_at && Carbon::parse($notice->last_sent_at)->isToday()) {
                        continue;   // one per deal per day
                    }
                    $since = Carbon::parse($notice->notified_through ?? $access->created_at);
                    $new = $this->documents->forDealWithFiledAt($deal, $this->scope->rolesOn($deal, $access))
                        ->filter(fn ($d) => $d->filed_at->gt($since))->values();
                    if ($new->isEmpty()) {
                        continue;
                    }

                    if (! $dryRun) {
                        $this->send($access, $deal, $new, $agency, $settings);
                        DB::table('attorney_portal_notices')->updateOrInsert(
                            ['access_id' => $access->id, 'deal_id' => $deal->id],
                            ['agency_id' => $access->agency_id, 'notified_through' => $new->max('filed_at'), 'last_sent_at' => now(), 'updated_at' => now(), 'created_at' => $notice->created_at ?? now()],
                        );
                        AttorneyPortalLogger::record($access, 'notified', ['deal_id' => $deal->id, 'meta' => ['documents' => $new->count()]], request());
                    }
                    $sent++;
                }
            }
        }

        return ['sent' => $sent, 'considered' => $considered];
    }

    private function send(AttorneyPortalAccess $access, $deal, $new, ?Agency $agency, AttorneyPortalSetting $settings): void
    {
        $kinds = $new->groupBy(fn ($d) => $d->documentType?->label ?? 'Document')->map->count()->all();
        $url = URL::temporarySignedRoute('attorney.open', now()->addDays($settings->linkValidDays()), ['access' => $access->id, 'deal' => $deal->id]);

        Mail::to($access->email)->send(new AttorneyPortalNewDocumentsMail(
            recipientName: $access->displayName(),
            agency: $agency,
            address: app(AttorneyPortalDealPresenter::class)->address($deal),
            kinds: $kinds,
            url: $url,
        ));
    }
}
