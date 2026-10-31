<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\TrainingVideo;
use Illuminate\Http\Request;

/**
 * Public, logged-out CoreX Training Videos page.
 *
 * Spec: .ai/specs/training-videos.md §4.3
 *
 * CoreX's own content — no agency branding, identical for every visitor. Sections are
 * the owner's groups alphabetically, then "More videos" for ungrouped ones.
 */
final class TrainingVideoController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $query = TrainingVideo::query()->publiclyVisible()->with('group');

        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $query->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('description', 'like', $like));
        }

        $videos = $query->orderByDesc('created_at')->orderByDesc('id')->get();

        $sections = $videos
            ->filter(fn (TrainingVideo $v) => $v->group !== null)
            ->groupBy('training_video_group_id')
            ->map(fn ($items) => ['name' => $items->first()->group->name, 'videos' => $items])
            ->sortBy(fn ($s) => mb_strtolower($s['name']))
            ->values();

        $ungrouped = $videos->filter(fn (TrainingVideo $v) => $v->group === null);
        if ($ungrouped->isNotEmpty()) {
            $sections->push(['name' => 'More videos', 'videos' => $ungrouped->values()]);
        }

        return view('public.training-videos', [
            'sections'     => $sections,
            'q'            => $q,
            'hasAny'       => $q === '' ? $videos->isNotEmpty() : TrainingVideo::query()->publiclyVisible()->exists(),
            'contactEmail' => 'support@corexos.co.za',
        ]);
    }
}
