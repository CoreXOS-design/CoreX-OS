<?php

namespace App\Http\Controllers\CoreX\Auctions;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\Auctions\SiteController;
use App\Models\Agency;
use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Services\Auctions\PublicAuctionSite;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Screen S9 — the Preview page (.ai/specs/auctions.md §16). Shows the agency's public auction site as a visitor will see it —
 * drafts included — beside an editor for its wording and pictures.
 *
 * The preview is rendered by the SAME controllers and views as the public site (a preview-mode PublicAuctionSite is swapped in),
 * so what is previewed is what is served. "Update preview" re-draws it with the edits typed so far and stores nothing; "Save"
 * stores them. A picture picked but not yet saved is kept in one per-person temporary file, replaced on every preview and
 * removed on save.
 */
class PreviewController extends Controller
{
    private const IMAGE_RULE = 'image|mimes:jpg,jpeg,png,webp|max:4096';

    // ── the editor page ────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $user = $request->user();
        $agency = $this->agency($request);
        $id = (int) $agency->id;

        $auctions = Auction::visibleTo($user)->where('status', '!=', Auction::STATUS_CANCELLED)
            ->with(['lots' => fn ($q) => $q->whereNotIn('status', [\App\Models\AuctionLot::STATUS_WITHDRAWN])->with('property:id,title')])
            ->orderByDesc('starts_at')->limit(60)->get();

        return view('corex.auctions.preview', [
            'agency' => $agency,
            'auctions' => $auctions,
            'values' => [
                'site_title' => AgencyAuctionSettings::siteTitleFor($id),
                'intro_text' => AgencyAuctionSettings::introTextFor($id),
                'contact_email' => AgencyAuctionSettings::contactEmailFor($id),
                'contact_phone' => AgencyAuctionSettings::contactPhoneFor($id),
                'accent_color' => AgencyAuctionSettings::accentColorFor($id),
                'how_it_works' => AgencyAuctionSettings::howItWorksFor($id),
                'footer_text' => AgencyAuctionSettings::footerTextFor($id),
                'show_past_auctions' => AgencyAuctionSettings::showPastAuctionsFor($id),
                'hero_url' => AgencyAuctionSettings::heroImageUrlFor($id),
            ],
            'brandColor' => Agency::publicBrandingFor($id)['colors']['default'],
            'startPath' => $this->pathFor((string) $request->query('page', 'home'), $auctions),
            'liveUrl' => route('public.auctions.home', $agency->slug),
            'canEdit' => $user->hasPermission('auctions.page.edit'),
            'frameUrl' => route('corex.auctions.preview.frame'),
        ]);
    }

    // ── the frame ──────────────────────────────────────────────────────────

    /** Renders one public page in preview mode. GET shows the saved state; POST also applies the unsaved edits in the form. */
    public function frame(Request $request)
    {
        $user = $request->user();
        $agency = $this->agency($request);
        $id = (int) $agency->id;
        $path = $this->cleanPath((string) $request->input('path', '/'));

        $settings = [];
        $edits = [];
        if ($request->isMethod('post')) {
            [$settings, $edits] = $this->unsavedEdits($request, $agency, $user);
        }

        // Swap the preview-mode site in for THIS render only, then put the real one back: preview mode must never outlive the request.
        $original = app(PublicAuctionSite::class);
        $site = $original->forPreview($user, $edits);
        app()->instance(PublicAuctionSite::class, $site);

        try {
            view()->share(['isPreview' => true, 'previewPath' => $path, 'previewNote' => $this->draftNote($path, $site, $agency)]);
            $html = AgencyAuctionSettings::withPreview($id, $settings, fn () => $this->render($path, $agency));
        } finally {
            app()->instance(PublicAuctionSite::class, $original);
            view()->share(['isPreview' => false, 'previewPath' => '/', 'previewNote' => '']);
        }

        return response($html)->header('X-Frame-Options', 'SAMEORIGIN')->header('X-Robots-Tag', 'noindex')->header('Cache-Control', 'no-store');
    }

    // ── save ───────────────────────────────────────────────────────────────

    public function save(Request $request)
    {
        $user = $request->user();
        $agency = $this->agency($request);
        $id = (int) $agency->id;

        $data = $this->validated($request);

        $write = [
            'site_title' => $data['site_title'] ?? null,
            'intro_text' => $data['intro_text'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'accent_color' => $this->nullIfBlank($data['accent_color'] ?? null),
            'how_it_works' => $data['how_it_works'] ?? null,
            'footer_text' => $data['footer_text'] ?? null,
            'show_past_auctions' => $request->boolean('show_past_auctions'),
        ];

        $row = AgencyAuctionSettings::query()->where('agency_id', $id)->first();
        if ($request->hasFile('hero_image')) {
            $this->deleteImage($row?->hero_image_path);
            $write['hero_image_path'] = $this->storeImage($request->file('hero_image'), $id, 'hero');
        } elseif ($request->boolean('remove_hero')) {
            $this->deleteImage($row?->hero_image_path);
            $write['hero_image_path'] = null;
        }
        AgencyAuctionSettings::updateOrCreate(['agency_id' => $id], $write);

        // the auctions edited on this page
        foreach ((array) $request->input('auctions', []) as $auctionId => $fields) {
            $auction = Auction::visibleTo($user)->find((int) $auctionId);
            if (! $auction || ! $auction->isEditable()) {
                continue;
            }
            $update = ['title' => trim((string) ($fields['title'] ?? '')) ?: $auction->title, 'description' => $this->nullIfBlank($fields['description'] ?? null)];
            $file = $request->file("cover_image.{$auctionId}");
            if ($file instanceof UploadedFile) {
                $this->deleteImage($auction->cover_image_path);
                $update['cover_image_path'] = $this->storeImage($file, $id, 'cover-' . $auction->id);
            } elseif (! empty($fields['remove_cover'])) {
                $this->deleteImage($auction->cover_image_path);
                $update['cover_image_path'] = null;
            }
            $auction->fill($update)->save();
        }

        $this->clearTemporary($id, $user->id);

        return redirect()->route('corex.auctions.preview', ['page' => $request->input('page', 'home')])->with('status', 'Saved. Published pages show the changes straight away.');
    }

    // ── internals ──────────────────────────────────────────────────────────

    private function render(string $path, Agency $agency): string
    {
        $site = app(SiteController::class);
        $slug = $agency->slug;

        if ($path === '/') {
            return $site->home($slug)->render();
        }
        if (preg_match('#^/a/(\d+)$#', $path, $m)) {
            return $site->auction($slug, (int) $m[1])->render();
        }
        if (preg_match('#^/a/(\d+)/lots/(\d+)$#', $path, $m)) {
            return $site->lot($slug, (int) $m[1], (int) $m[2])->render();
        }

        return view('public.auctions.preview-na', SiteController::layoutData($agency, null))->render();
    }

    /** The pages the preview can show: Home, an auction, a lot. Anything else is cleaned back to Home. */
    private function cleanPath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '/' || preg_match('#^/a/\d+(/lots/\d+)?$#', $path) || preg_match('#^/[a-z\-/]*$#', $path) ? $path : '/';
    }

    private function pathFor(string $page, $auctions): string
    {
        if (preg_match('/^auction:(\d+)$/', $page, $m) && $auctions->contains('id', (int) $m[1])) {
            return '/a/' . $m[1];
        }
        if (preg_match('/^lot:(\d+)$/', $page, $m)) {
            foreach ($auctions as $a) {
                if ($lot = $a->lots->firstWhere('id', (int) $m[1])) {
                    return "/a/{$a->id}/lots/{$lot->id}";
                }
            }
        }

        return '/';
    }

    private function draftNote(string $path, PublicAuctionSite $site, Agency $agency): string
    {
        if (! preg_match('#^/a/(\d+)#', $path, $m)) {
            return '';
        }
        try {
            $auction = $site->auction($agency, (int) $m[1]);
        } catch (\Throwable) {
            return '';
        }

        return $auction->status === Auction::STATUS_DRAFT ? 'This auction is a draft: only you can see it until you publish it.' : '';
    }

    /**
     * The edits typed into the form but not saved, in the shapes the preview needs. A picture picked but not saved is written to
     * one temporary file for this person (replaced each time) so the preview can show it.
     *
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    private function unsavedEdits(Request $request, Agency $agency, $user): array
    {
        $id = (int) $agency->id;
        $accent = (string) $request->input('accent_color', '');
        $settings = [
            'site_title' => $this->nullIfBlank($request->input('site_title')),
            'intro_text' => $this->nullIfBlank($request->input('intro_text')),
            'contact_email' => $this->nullIfBlank($request->input('contact_email')),
            'contact_phone' => $this->nullIfBlank($request->input('contact_phone')),
            'accent_color' => preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? $accent : null,
            'how_it_works' => $this->nullIfBlank($request->input('how_it_works')),
            'footer_text' => $this->nullIfBlank($request->input('footer_text')),
            'show_past_auctions' => $request->boolean('show_past_auctions'),
        ];

        $hero = $request->file('hero_image');
        if ($hero instanceof UploadedFile && $hero->isValid() && str_starts_with((string) $hero->getMimeType(), 'image/')) {
            $settings['hero_image_path'] = $this->storeTemporary($hero, $id, $user->id, 'hero');
        } elseif ($request->boolean('remove_hero')) {
            $settings['hero_image_path'] = null;
        }

        $edits = [];
        foreach ((array) $request->input('auctions', []) as $auctionId => $fields) {
            $edit = [];
            if (trim((string) ($fields['title'] ?? '')) !== '') {
                $edit['title'] = mb_substr(trim((string) $fields['title']), 0, 200);
            }
            if (array_key_exists('description', (array) $fields)) {
                $edit['description'] = $this->nullIfBlank($fields['description']);
            }
            $file = $request->file("cover_image.{$auctionId}");
            if ($file instanceof UploadedFile && $file->isValid() && str_starts_with((string) $file->getMimeType(), 'image/')) {
                $edit['cover_image_path'] = $this->storeTemporary($file, $id, $user->id, 'cover-' . (int) $auctionId);
            } elseif (! empty($fields['remove_cover'])) {
                $edit['cover_image_path'] = null;
            }
            if ($edit) {
                $edits[(int) $auctionId] = $edit;
            }
        }

        return [$settings, $edits];
    }

    /** Server-side rules for a real save. */
    private function validated(Request $request): array
    {
        $rules = [
            'site_title' => 'nullable|string|max:160',
            'intro_text' => 'nullable|string|max:2000',
            'contact_email' => 'nullable|email|max:190',
            'contact_phone' => 'nullable|string|max:40',
            'accent_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'how_it_works' => 'nullable|string|max:5000',
            'footer_text' => 'nullable|string|max:1000',
            'hero_image' => 'nullable|' . self::IMAGE_RULE,
            'auctions' => 'nullable|array',
            'auctions.*.title' => 'nullable|string|max:200',
            'auctions.*.description' => 'nullable|string|max:20000',
            'cover_image' => 'nullable|array',
            'cover_image.*' => 'nullable|' . self::IMAGE_RULE,
        ];

        try {
            return $request->validate($rules, ['accent_color.regex' => 'Pick the colour with the colour box, or type it like #0b2a4a.']);
        } catch (ValidationException $e) {
            throw $e;
        }
    }

    private function storeImage(UploadedFile $file, int $agencyId, string $stem): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');

        return $file->storeAs("auction-pages/{$agencyId}", $stem . '-' . bin2hex(random_bytes(4)) . '.' . $ext, 'public');
    }

    private function storeTemporary(UploadedFile $file, int $agencyId, int $userId, string $stem): string
    {
        $this->clearTemporary($agencyId, $userId, $stem);
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');

        return $file->storeAs("auction-pages/{$agencyId}/preview", "u{$userId}-{$stem}.{$ext}", 'public');
    }

    /** Remove this person's temporary preview pictures (all of them, or one stem). */
    private function clearTemporary(int $agencyId, int $userId, ?string $stem = null): void
    {
        $disk = Storage::disk('public');
        foreach ($disk->files("auction-pages/{$agencyId}/preview") as $file) {
            $name = basename($file);
            if (str_starts_with($name, "u{$userId}-") && ($stem === null || str_starts_with($name, "u{$userId}-{$stem}."))) {
                $disk->delete($file);
            }
        }
    }

    private function deleteImage(?string $path): void
    {
        if ($path && ! str_contains($path, '/preview/')) {
            Storage::disk('public')->delete($path);
        }
    }

    private function agency(Request $request): Agency
    {
        return Agency::withoutGlobalScopes()->findOrFail((int) $request->user()->effectiveAgencyId());
    }

    private function nullIfBlank(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : $s;
    }
}
