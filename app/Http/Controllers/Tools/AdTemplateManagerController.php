<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\PropertyAdTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Template Manager (Tools → Ad Manager → Template Manager).
 * Spec: .ai/specs/ad-manager.md §19.
 *
 * The one place to see, find, edit, archive and restore every custom ad template
 * the agency owns. Create / Edit hand off to the Ad Builder
 * (PropertyAdTemplateController) — this controller only lists and archives/restores.
 *
 * Scoping: PropertyAdTemplate is BelongsToAgency, so every query here — the list,
 * and the route-model lookup behind archive/restore — is confined to the user's
 * agency at the query layer. A template id from another agency is a 404, not a 403.
 * Whether the user may CHANGE a given template is PropertyAdTemplate::canBeManagedBy()
 * (creator, or `properties.ad_templates.manage`), re-checked here on every write.
 */
class AdTemplateManagerController extends Controller
{
    private const PER_PAGE = 15;

    private const SORTS = ['name', 'creator', 'created', 'updated'];

    public function index(Request $request)
    {
        $user = $request->user();

        $search  = trim((string) $request->query('q', ''));
        $status  = in_array($request->query('status'), ['active', 'archived', 'all'], true)
            ? $request->query('status') : 'active';
        $mine    = $request->query('creator') === 'mine';
        $sort    = in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'updated';
        // Dates default newest-first, text columns A→Z, unless the URL says otherwise.
        $dir     = in_array($request->query('dir'), ['asc', 'desc'], true)
            ? $request->query('dir')
            : (in_array($sort, ['updated', 'created'], true) ? 'desc' : 'asc');
        $from    = $this->validDate($request->query('from'));
        $to      = $this->validDate($request->query('to'));

        $query = PropertyAdTemplate::query()->with('user:id,name');

        if ($status === 'archived') {
            $query->onlyTrashed();
        } elseif ($status === 'all') {
            $query->withTrashed();
        }

        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                  ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like));
            });
        }

        if ($mine) {
            $query->where('user_id', $user->id);
        }
        if ($from) {
            $query->whereDate('updated_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('updated_at', '<=', $to);
        }

        // Creator sort uses a raw users lookup (not the User model) so no global scope
        // can silently drop a creator's row and scramble the order.
        match ($sort) {
            'name'    => $query->orderBy('name', $dir),
            'creator' => $query->orderBy(
                DB::table('users')->select('name')->whereColumn('users.id', 'property_ad_templates.user_id'),
                $dir
            ),
            'created' => $query->orderBy('created_at', $dir),
            default   => $query->orderBy('updated_at', $dir),
        };
        $query->orderBy('id', $dir); // deterministic tie-breaker

        $templates = $query->paginate(self::PER_PAGE)->withQueryString();

        // Per-row facts the view needs, computed once here so the Blade stays dumb.
        $templates->getCollection()->transform(function (PropertyAdTemplate $t) use ($user) {
            $layout   = (array) $t->layout_json;
            $variants = $layout['variants'] ?? [];
            $t->setAttribute('canvas_label', ! empty($layout['canvasW']) && ! empty($layout['canvasH'])
                ? ((int) $layout['canvasW'] . ' × ' . (int) $layout['canvasH']) : '—');
            $t->setAttribute('variant_count', is_array($variants) ? count($variants) : 0);
            $t->setAttribute('can_manage', $t->canBeManagedBy($user));

            return $t;
        });

        // "Is the library empty" (vs. "nothing matches") for the right empty state.
        $agencyHasAny = PropertyAdTemplate::withTrashed()->exists();

        return view('tools.ad-manager.templates', [
            'templates'    => $templates,
            'agencyHasAny' => $agencyHasAny,
            'filters'      => [
                'q' => $search, 'status' => $status, 'creator' => $mine ? 'mine' : 'all',
                'sort' => $sort, 'dir' => $dir, 'from' => $from, 'to' => $to,
            ],
            'canBuild'     => $user->hasPermission('access_properties'),
        ]);
    }

    public function archive(PropertyAdTemplate $template): RedirectResponse
    {
        $this->authorizeTemplate($template);
        $template->delete(); // SoftDeletes — never a hard delete (non-negotiable #1)

        return back()->with('success', '"' . $template->name . '" archived. Restore it any time from the Archived filter.');
    }

    public function restore(PropertyAdTemplate $template): RedirectResponse
    {
        $this->authorizeTemplate($template);
        $template->restore();

        return back()->with('success', '"' . $template->name . '" restored — it is back in the template pickers.');
    }

    private function authorizeTemplate(PropertyAdTemplate $template): void
    {
        if (! $template->canBeManagedBy(auth()->user())) {
            abort(403);
        }
    }

    private function validDate(mixed $value): ?string
    {
        $v = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
    }
}
