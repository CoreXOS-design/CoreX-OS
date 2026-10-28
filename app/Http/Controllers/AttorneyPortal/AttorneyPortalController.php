<?php

namespace App\Http\Controllers\AttorneyPortal;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AttorneyPortalAccess;
use App\Services\AttorneyPortal\AttorneyPortalAccessService;
use App\Services\AttorneyPortal\AttorneyPortalDealPresenter;
use App\Services\AttorneyPortal\AttorneyPortalDocuments;
use App\Services\AttorneyPortal\AttorneyPortalLogger;
use App\Services\AttorneyPortal\AttorneyPortalScope;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * .ai/specs/attorney-portal.md - the attorney's side: the emailed link, the emailed code, "My deals", one deal, a
 * download. DOWNLOAD ONLY (R1). The unknown / expired / revoked link is a plain 404 - it never says which.
 */
class AttorneyPortalController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private AttorneyPortalAccessService $service,
        private AttorneyPortalScope $scope,
        private AttorneyPortalDealPresenter $presenter,
        private AttorneyPortalDocuments $documents,
    ) {}

    // ── sign-in: link + emailed code ────────────────────────────────────────

    public function link(Request $request, string $token)
    {
        $access = $this->accessOr404($token);

        // Already signed in on this browser as this very person: straight to the deals.
        $existing = $this->service->sessionFor($request->cookie(config('attorney-portal.cookie')));
        if ($existing && (int) $existing->access_id === (int) $access->id) {
            return redirect()->route('attorney.deals');
        }

        AttorneyPortalLogger::record($access, 'link_opened', [], $request);

        return view('attorney-portal.link', $this->shell($access) + [
            'token'      => $token,
            'maskedMail' => $this->mask($access->email),
            'codeSent'   => (bool) session('attorney_code_sent_' . $access->id),
        ]);
    }

    public function sendCode(Request $request, string $token)
    {
        $access = $this->accessOr404($token);

        $blocked = $this->service->sendCode($access, $request);
        if ($blocked) {
            return redirect()->route('attorney.link', $token)->withErrors([
                'code' => $blocked === 'cooldown'
                    ? 'A code was only just sent. Please wait a minute and try again.'
                    : 'Too many codes were requested. Please try again in an hour.',
            ]);
        }
        session(['attorney_code_sent_' . $access->id => true]);

        return redirect()->route('attorney.link', $token)->with('status', 'We emailed a code to ' . $this->mask($access->email) . '.');
    }

    public function verify(Request $request, string $token)
    {
        $access = $this->accessOr404($token);
        $data   = $request->validate(['code' => ['required', 'string', 'max:12']]);

        $raw = $this->service->verifyCode($access, $data['code'], $request);
        if (! $raw) {
            session(['attorney_code_sent_' . $access->id => true]);

            return redirect()->route('attorney.link', $token)->withErrors(['code' => 'That code is not right, or it has expired. Check the latest email, or ask for a new code.']);
        }

        $minutes = (int) config('attorney-portal.session_hours', 12) * 60;
        $cookie  = cookie(config('attorney-portal.cookie'), $raw, $minutes, '/attorney', null, $request->isSecure(), true, false, 'lax');

        return redirect()->route('attorney.deals')->withCookie($cookie);
    }

    // ── the signed link in a "new documents" email: the same code sign-in, then straight to that deal ──

    public function open(Request $request, int $access, int $deal)
    {
        $model = $this->service->accessForId($access);
        abort_if(! $model, 404);
        $model->loadMissing(['supplierContact', 'firm']);

        $existing = $this->service->sessionFor($request->cookie(config('attorney-portal.cookie')));
        if ($existing && (int) $existing->access_id === (int) $model->id) {
            return redirect()->route('attorney.deal', $deal);
        }

        AttorneyPortalLogger::record($model, 'link_opened', ['deal_id' => $deal, 'meta' => ['via' => 'email_notice']], $request);

        return view('attorney-portal.link', $this->shell($model) + [
            'token'      => null,
            'maskedMail' => $this->mask($model->email),
            'codeSent'   => (bool) session('attorney_code_sent_' . $model->id),
            'codeUrl'    => $this->signedLike($request, 'attorney.open.code', $access, $deal),
            'verifyUrl'  => $this->signedLike($request, 'attorney.open.verify', $access, $deal),
        ]);
    }

    public function openCode(Request $request, int $access, int $deal)
    {
        $model = $this->service->accessForId($access);
        abort_if(! $model, 404);

        $blocked = $this->service->sendCode($model, $request);
        $back = $this->signedLike($request, 'attorney.open', $access, $deal);
        if ($blocked) {
            return redirect($back)->withErrors(['code' => $blocked === 'cooldown'
                ? 'A code was only just sent. Please wait a minute and try again.'
                : 'Too many codes were requested. Please try again in an hour.']);
        }
        session(['attorney_code_sent_' . $model->id => true]);

        return redirect($back)->with('status', 'We emailed a code to ' . $this->mask($model->email) . '.');
    }

    public function openVerify(Request $request, int $access, int $deal)
    {
        $model = $this->service->accessForId($access);
        abort_if(! $model, 404);
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);

        $raw = $this->service->verifyCode($model, $data['code'], $request);
        if (! $raw) {
            session(['attorney_code_sent_' . $model->id => true]);

            return redirect($this->signedLike($request, 'attorney.open', $access, $deal))
                ->withErrors(['code' => 'That code is not right, or it has expired. Check the latest email, or ask for a new code.']);
        }

        $cookie = cookie(config('attorney-portal.cookie'), $raw, (int) config('attorney-portal.session_hours', 12) * 60, '/attorney', null, $request->isSecure(), true, false, 'lax');

        return redirect()->route('attorney.deal', $deal)->withCookie($cookie);
    }

    /** A signed URL for another of the open routes, expiring when the one in hand does (so a code page never outlives its email link). */
    private function signedLike(Request $request, string $route, int $access, int $deal): string
    {
        $expires = (int) $request->query('expires', 0);

        return \Illuminate\Support\Facades\URL::temporarySignedRoute($route, $expires > time() ? \Illuminate\Support\Carbon::createFromTimestamp($expires) : now()->addHour(), ['access' => $access, 'deal' => $deal]);
    }

    public function signOut(Request $request)
    {
        $this->service->signOut($request->attributes->get('attorney_session'), $request);

        return redirect()->route('attorney.signed-out')->withCookie(cookie()->forget(config('attorney-portal.cookie'), '/attorney'));
    }

    public function signedOut()
    {
        return view('attorney-portal.signed-out');
    }

    // ── My deals ────────────────────────────────────────────────────────────

    public function deals(Request $request)
    {
        $access = $this->access($request);

        $rows = $this->scope->visibleDeals($access)->map(fn ($d) => $this->presenter->row($d, $access));
        $total = $rows->count();

        $q      = trim((string) $request->query('q', ''));
        $filter = in_array($request->query('show'), ['open', 'finished'], true) ? $request->query('show') : 'all';
        $from   = $this->parseDate($request->query('from'));
        $to     = $this->parseDate($request->query('to'));
        $sort   = in_array($request->query('sort'), ['property', 'parties', 'agent', 'stage', 'latest'], true) ? $request->query('sort') : 'latest';
        $dir    = $request->query('dir') === 'asc' ? 'asc' : ($request->query('dir') === 'desc' ? 'desc' : ($sort === 'latest' ? 'desc' : 'asc'));

        $rows = $rows->filter(function (array $r) use ($q, $filter, $from, $to) {
            if ($filter === 'open' && $r['finished']) return false;
            if ($filter === 'finished' && ! $r['finished']) return false;
            if ($from || $to) {
                $d = $r['latest_document'];
                if (! $d) return false;
                if ($from && $d->lt($from->copy()->startOfDay())) return false;
                if ($to && $d->gt($to->copy()->endOfDay())) return false;
            }
            if ($q !== '') {
                $hay = mb_strtolower(implode(' ', [$r['address'], $r['buyer'], $r['seller'], $r['reference'] ?? '']));
                foreach (preg_split('/\s+/', mb_strtolower($q)) as $term) {
                    if ($term !== '' && ! str_contains($hay, $term)) return false;
                }
            }
            return true;
        });

        $rows = $this->sorted($rows, $sort, $dir);

        $page  = max(1, (int) $request->query('page', 1));
        $slice = new LengthAwarePaginator(
            $rows->forPage($page, self::PER_PAGE)->values(), $rows->count(), self::PER_PAGE, $page,
            ['path' => route('attorney.deals'), 'query' => $request->query()]
        );

        AttorneyPortalLogger::record($access, 'deals_listed', ['meta' => ['shown' => $rows->count(), 'q' => $q ?: null]], $request);

        return view('attorney-portal.deals', $this->shell($access) + [
            'deals' => $slice, 'q' => $q, 'show' => $filter, 'sort' => $sort, 'dir' => $dir,
            'from' => $request->query('from'), 'to' => $request->query('to'), 'hasAny' => $total > 0,
        ]);
    }

    // ── One deal ────────────────────────────────────────────────────────────

    public function show(Request $request, int $deal)
    {
        $access = $this->access($request);
        $model  = $this->dealOr404($access, $deal, $request);

        AttorneyPortalLogger::record($access, 'deal_viewed', ['deal_id' => $model->id], $request);

        return view('attorney-portal.deal', $this->shell($access) + ['d' => $this->presenter->detail($model, $access)]);
    }

    // ── A document ──────────────────────────────────────────────────────────

    public function document(Request $request, int $deal, int $document, string $mode = 'download'): Response
    {
        $access = $this->access($request);
        $model  = $this->dealOr404($access, $deal, $request);
        $doc    = $this->documents->find($model, $this->scope->rolesOn($model, $access), $document);

        if (! $doc) {
            AttorneyPortalLogger::record($access, 'refused', ['deal_id' => $model->id, 'document_id' => $document, 'meta' => ['why' => 'document_not_offered']], $request);
            abort(404);
        }

        $inline = $mode === 'view' ? $doc->inlineMimeType() : null;
        AttorneyPortalLogger::record($access, $inline ? 'document_viewed' : 'document_downloaded', ['deal_id' => $model->id, 'document_id' => $doc->id], $request);

        $bytes = $doc->decryptedContents();
        abort_if($bytes === null, 404);
        $name = str_replace(['"', "\r", "\n"], '', (string) $doc->original_name);

        return response($bytes, 200, [
            'Content-Type'           => $inline ?: ($doc->mime_type ?: 'application/octet-stream'),
            'Content-Disposition'    => ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"',
            'Cache-Control'          => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function accessOr404(string $token): AttorneyPortalAccess
    {
        $access = $this->service->accessForLink($token);
        abort_if(! $access, 404);
        $access->loadMissing(['supplierContact', 'firm']);

        return $access;
    }

    private function access(Request $request): AttorneyPortalAccess
    {
        $access = $request->attributes->get('attorney_access');
        $access->loadMissing(['supplierContact', 'firm']);

        return $access;
    }

    private function dealOr404(AttorneyPortalAccess $access, int $dealId, Request $request)
    {
        $deal = $this->scope->find($access, $dealId);
        if (! $deal) {
            AttorneyPortalLogger::record($access, 'refused', ['deal_id' => $dealId, 'meta' => ['why' => 'deal_not_theirs']], $request);
            abort(404);
        }

        return $deal;
    }

    private function shell(AttorneyPortalAccess $access): array
    {
        $branding = Agency::publicBrandingFor((int) $access->agency_id);

        return [
            'agencyName'  => $branding['name'],
            'agencyLogo'  => $branding['logoUrl'],
            'brandColor'  => $branding['colors']['default'],
            'buttonColor' => $branding['colors']['button'],
            'personName'  => $access->displayName(),
            'firmName'    => $access->firm?->name,
        ];
    }

    private function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1) . str_repeat('*', max(2, mb_strlen($local) - 1)) . '@' . $domain;
    }

    private function parseDate($value): ?Carbon
    {
        try {
            return $value ? Carbon::parse((string) $value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function sorted(Collection $rows, string $sort, string $dir): Collection
    {
        $key = match ($sort) {
            'property' => fn ($r) => mb_strtolower($r['address']),
            'parties'  => fn ($r) => mb_strtolower($r['buyer'] . ' ' . $r['seller']),
            'agent'    => fn ($r) => mb_strtolower((string) $r['agent']),
            'stage'    => fn ($r) => mb_strtolower($r['stage']),
            default    => fn ($r) => $r['latest_document']?->timestamp ?? 0,
        };

        return $rows->sortBy($key, SORT_REGULAR, $dir === 'desc')->values();
    }
}
