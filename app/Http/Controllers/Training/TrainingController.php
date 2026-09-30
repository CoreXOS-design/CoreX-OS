<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\TrainingCompletion;
use App\Models\TrainingCourse;
use App\Models\TrainingLesson;
use App\Models\TrainingProgress;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TrainingController extends Controller
{
    // ── Agent-facing ──

    public function index()
    {
        $user = auth()->user();
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);   // AT-253 Rule 17

        $courses = TrainingCourse::where('agency_id', $agencyId)
            ->published()
            ->orderBy('sort_order')
            ->withCount(['lessons' => fn($q) => $q->where('is_published', true)])
            ->get();

        return view('training.index', compact('courses'));
    }

    public function show($courseId)
    {
        $course = TrainingCourse::with(['lessons' => fn($q) => $q->where('is_published', true)->orderBy('sort_order')])
            ->published()
            ->findOrFail($courseId);

        $userId = auth()->id();
        $completion = $course->completionForUser($userId);

        return view('training.show', compact('course', 'completion'));
    }

    /**
     * AT-424 — TrainingLesson has no agency of its own; it belongs to the
     * agency of its course, and TrainingCourse is agency-scoped. Resolving the
     * course through the scope 404s another agency's lesson.
     */
    private function visibleLesson($lessonId): TrainingLesson
    {
        $lesson = TrainingLesson::findOrFail($lessonId);
        abort_unless(TrainingCourse::whereKey($lesson->course_id)->exists(), 404);
        return $lesson;
    }

    public function startLesson($lessonId)
    {
        $lesson = $this->visibleLesson($lessonId);

        TrainingProgress::firstOrCreate(
            ['user_id' => auth()->id(), 'lesson_id' => $lesson->id],
            ['course_id' => $lesson->course_id, 'started_at' => now()]
        );

        return back();
    }

    public function completeLesson($lessonId)
    {
        $lesson = $this->visibleLesson($lessonId);
        $userId = auth()->id();

        $progress = TrainingProgress::firstOrCreate(
            ['user_id' => $userId, 'lesson_id' => $lesson->id],
            ['course_id' => $lesson->course_id, 'started_at' => now()]
        );

        $progress->update(['completed_at' => now()]);

        return back()->with('success', 'Lesson completed.');
    }

    /**
     * TrainingCourse is agency-scoped (BelongsToAgency), so its lesson documents are
     * proprietary training content, not public material — they must not be reachable via
     * a bare asset('storage/...') URL. New uploads (below) go to the private 'local' disk;
     * this streams them back after checking the requesting user's agency actually owns the
     * course. Mirrors the compliance-document download pattern in
     * AgencyDocumentsViewerController::download (local-disk-first, public-disk fallback for
     * files uploaded before this fix, ownership check before streaming).
     */
    public function downloadLessonDocument($lessonId)
    {
        $lesson = TrainingLesson::with('course')->findOrFail($lessonId);
        $course = $lesson->course;

        $user = auth()->user();
        abort_unless($course && $course->agency_id === $user?->effectiveAgencyId(), 403);

        abort_unless($lesson->document_path, 404, 'No document attached to this lesson.');

        $disk = Storage::disk('local')->exists($lesson->document_path) ? 'local' : 'public';

        abort_unless(Storage::disk($disk)->exists($lesson->document_path), 404, 'Document file is missing from storage.');

        return Storage::disk($disk)->download($lesson->document_path);
    }

    public function acknowledgeCourse($courseId, Request $request)
    {
        $course = TrainingCourse::findOrFail($courseId);
        $userId = auth()->id();

        // Verify all lessons completed
        $totalLessons = $course->lessonCount();
        $completedLessons = $course->completedLessonCountForUser($userId);

        if ($completedLessons < $totalLessons) {
            return back()->with('error', 'Complete all lessons before acknowledging.');
        }

        $validated = $request->validate([
            'signature' => ['nullable', 'string', 'max:50000'],
        ]);

        TrainingCompletion::updateOrCreate(
            ['user_id' => $userId, 'course_id' => $course->id],
            [
                'completed_at' => now(),
                'acknowledged_at' => now(),
                'acknowledgement_signature' => $validated['signature'] ?? null,
                'expires_at' => now()->addYear()->toDateString(),
            ]
        );

        return back()->with('success', 'Course acknowledged. Valid for 12 months.');
    }

    // ── Admin ──
    // 2026-09-30 (urgent, Johan) — every method in this section used to
    // abort_unless($user->isOwnerRole() || $user->effectiveRole() === 'super_admin'),
    // a hardcoded role check that never consulted training.manage — the
    // dedicated, Role-Manager-settable permission this feature already
    // exposes (config/corex-permissions.php:323) and already grants to
    // 'admin' on agency 1. Johan's own admin account 403'd here despite
    // Role Manager showing him with access, because nothing in this
    // controller ever looked at that grant. hasPermission() already
    // bypasses for owners/super_admin (PermissionService::userHasPermission()),
    // so this is a strict widening, not a narrowing, of who can reach these.

    public function manage()
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('training.manage'), 403);

        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);   // AT-253 Rule 17
        $courses = TrainingCourse::where('agency_id', $agencyId)
            ->withCount(['lessons'])
            ->orderBy('sort_order')
            ->get();

        return view('training.manage', compact('courses'));
    }

    public function createCourse()
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('training.manage'), 403);

        return view('training.course-form', ['course' => null]);
    }

    public function storeCourse(Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('training.manage'), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', 'in:compliance,onboarding,sales,systems,general'],
            'is_required' => ['nullable', 'boolean'],
            'is_required_for_activation' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        // AT-253 (STANDARDS Rule 17) — training_courses.agency_id is NOT NULL. The old `?? 1`
        // published a no-agency user's course into AGENCY 1's training library, visible to a
        // tenant that never authored it.
        $agencyId = $user->effectiveAgencyId();
        if (! $agencyId) {
            throw new \App\Exceptions\MissingAgencyContextException('a training course');
        }

        $validated['agency_id'] = $agencyId;
        $validated['created_by'] = $user->id;
        $validated['is_required'] = $request->boolean('is_required');
        $validated['is_required_for_activation'] = $request->boolean('is_required_for_activation');
        $validated['is_published'] = $request->boolean('is_published', true);

        $course = TrainingCourse::create($validated);

        return redirect()->route('training.manage')
            ->with('success', "Course \"{$course->title}\" created.");
    }

    public function editCourse($id)
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('training.manage'), 403);

        $course = TrainingCourse::findOrFail($id);

        return view('training.course-form', compact('course'));
    }

    /**
     * 2026-09-30 (Johan, urgent) — "nobody can tell who has done the
     * training." Per-course completion report: who has acknowledged the
     * course (App\Models\TrainingCompletion, already created by
     * acknowledgeCourse() above), who is still outstanding, and their
     * in-progress lesson percent. Search (name/email), sort (name /
     * status / completed date, default: outstanding-first then name),
     * filter (status + completed-date range), pagination, and a real
     * empty state per BUILD_STANDARD.md §1b.
     *
     * Scoping (§1c, own/branch/agency): gated behind training.manage like
     * every other admin method here, then the ROSTER of learners shown is
     * further narrowed by PermissionService::getDataScope($user,
     * 'training') — the same own/branch/all grant already exposed in Role
     * Manager for the pre-existing training.view permission (any module
     * with a `{module}.view` action key automatically gets the
     * own/branch/all selector there, no new config needed). 'own' shows
     * only the viewer's own row, 'branch' the viewer's branch, 'all' the
     * whole agency — this is about which LEARNERS' rows a manager may
     * see, layered on top of (never a substitute for) the outer
     * agency_id boundary users are already scoped to via AgencyScope.
     */
    public function completions($courseId, Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('training.manage'), 403);

        $course = TrainingCourse::findOrFail($courseId);

        $scope = PermissionService::getDataScope($user, 'training') ?? 'own';

        $query = User::query()
            ->leftJoin('training_completions', function ($join) use ($course) {
                $join->on('training_completions.user_id', '=', 'users.id')
                    ->where('training_completions.course_id', '=', $course->id);
            })
            ->select('users.*', 'training_completions.completed_at as tc_completed_at', 'training_completions.acknowledged_at as tc_acknowledged_at');

        if ($scope === 'branch') {
            $query->where('users.branch_id', $user->effectiveBranchId());
        } elseif ($scope === 'own') {
            $query->where('users.id', $user->id);
        }

        if ($request->filled('q')) {
            $q = trim((string) $request->string('q'));
            $query->where(function ($w) use ($q) {
                $w->where('users.name', 'like', "%{$q}%")
                    ->orWhere('users.email', 'like', "%{$q}%");
            });
        }

        if ($request->filled('status')) {
            $status = $request->string('status')->toString();
            if ($status === 'completed') {
                $query->whereNotNull('training_completions.completed_at');
            } elseif ($status === 'outstanding') {
                $query->whereNull('training_completions.completed_at');
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('training_completions.completed_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('training_completions.completed_at', '<=', $request->date('date_to'));
        }

        $sortable = [
            'name' => 'users.name',
            'status' => 'training_completions.completed_at',
            'completed_at' => 'training_completions.completed_at',
        ];
        $sort = $sortable[$request->string('sort')->toString()] ?? 'training_completions.completed_at';
        $direction = $request->filled('direction')
            ? ($request->string('direction')->toString() === 'desc' ? 'desc' : 'asc')
            : 'asc'; // default: outstanding (NULL completed_at) first — the actionable list

        $perPageOptions = [10, 25, 50, 100];
        $rawPerPage = $request->integer('per_page', 25);
        $perPage = collect($perPageOptions)->first(fn ($opt) => $rawPerPage <= $opt) ?? end($perPageOptions);

        $learners = $query
            ->orderBy($sort, $direction)
            ->orderBy('users.name', 'asc')
            ->paginate($perPage)
            ->withQueryString();

        $learners->getCollection()->transform(function ($learner) use ($course) {
            $learner->training_percent = $course->completionPercentForUser($learner->id);

            return $learner;
        });

        return view('training.completions', compact('course', 'learners', 'scope'));
    }

    public function updateCourse($id, Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('training.manage'), 403);

        $course = TrainingCourse::findOrFail($id);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', 'in:compliance,onboarding,sales,systems,general'],
            'is_required' => ['nullable', 'boolean'],
            'is_required_for_activation' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $validated['is_required'] = $request->boolean('is_required');
        $validated['is_required_for_activation'] = $request->boolean('is_required_for_activation');
        $validated['is_published'] = $request->boolean('is_published');

        $course->update($validated);

        return redirect()->route('training.manage')
            ->with('success', "Course updated.");
    }

    public function createLesson($courseId)
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('training.manage'), 403);

        $course = TrainingCourse::findOrFail($courseId);

        return view('training.lesson-form', ['course' => $course, 'lesson' => null]);
    }

    public function storeLesson($courseId, Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('training.manage'), 403);

        $course = TrainingCourse::findOrFail($courseId);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'content_type' => ['required', 'in:text,video_url,document,link'],
            'video_url' => ['nullable', 'string', 'max:500'],
            'external_link' => ['nullable', 'string', 'max:500'],
            'document_file' => ['nullable', 'file', 'max:10240'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:480'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $validated['course_id'] = $course->id;
        $validated['is_published'] = $request->boolean('is_published', true);
        unset($validated['document_file']);

        if ($request->hasFile('document_file')) {
            // TrainingCourse is agency-scoped proprietary content — store on the private
            // 'local' disk and serve it through downloadLessonDocument()'s ownership check,
            // never the public disk (see training.show blade for the gated download link).
            $validated['document_path'] = $request->file('document_file')
                ->store('training/lessons/' . $course->id, 'local');
        }

        TrainingLesson::create($validated);

        return redirect()->route('training.edit-course', $course)
            ->with('success', 'Lesson added.');
    }

    public function editLesson($lessonId)
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('training.manage'), 403);

        $lesson = TrainingLesson::with('course')->findOrFail($lessonId);

        return view('training.lesson-form', ['course' => $lesson->course, 'lesson' => $lesson]);
    }

    public function updateLesson($lessonId, Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('training.manage'), 403);

        $lesson = TrainingLesson::findOrFail($lessonId);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'content_type' => ['required', 'in:text,video_url,document,link'],
            'video_url' => ['nullable', 'string', 'max:500'],
            'external_link' => ['nullable', 'string', 'max:500'],
            'document_file' => ['nullable', 'file', 'max:10240'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:480'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $validated['is_published'] = $request->boolean('is_published');
        unset($validated['document_file']);

        if ($request->hasFile('document_file')) {
            // Same as storeLesson(): private 'local' disk, gated download, no public URL.
            $validated['document_path'] = $request->file('document_file')
                ->store('training/lessons/' . $lesson->course_id, 'local');
        }

        $lesson->update($validated);

        return redirect()->route('training.edit-course', $lesson->course_id)
            ->with('success', 'Lesson updated.');
    }
}
