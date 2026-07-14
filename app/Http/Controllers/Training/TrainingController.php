<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\TrainingCompletion;
use App\Models\TrainingCourse;
use App\Models\TrainingLesson;
use App\Models\TrainingProgress;
use Illuminate\Http\Request;

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

    public function startLesson($lessonId)
    {
        $lesson = TrainingLesson::findOrFail($lessonId);

        TrainingProgress::firstOrCreate(
            ['user_id' => auth()->id(), 'lesson_id' => $lesson->id],
            ['course_id' => $lesson->course_id, 'started_at' => now()]
        );

        return back();
    }

    public function completeLesson($lessonId)
    {
        $lesson = TrainingLesson::findOrFail($lessonId);
        $userId = auth()->id();

        $progress = TrainingProgress::firstOrCreate(
            ['user_id' => $userId, 'lesson_id' => $lesson->id],
            ['course_id' => $lesson->course_id, 'started_at' => now()]
        );

        $progress->update(['completed_at' => now()]);

        return back()->with('success', 'Lesson completed.');
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

    public function manage()
    {
        $user = auth()->user();
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

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
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

        return view('training.course-form', ['course' => null]);
    }

    public function storeCourse(Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

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
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

        $course = TrainingCourse::findOrFail($id);

        return view('training.course-form', compact('course'));
    }

    public function updateCourse($id, Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

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
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

        $course = TrainingCourse::findOrFail($courseId);

        return view('training.lesson-form', ['course' => $course, 'lesson' => null]);
    }

    public function storeLesson($courseId, Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

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
            $validated['document_path'] = $request->file('document_file')
                ->store('training/lessons/' . $course->id, 'public');
        }

        TrainingLesson::create($validated);

        return redirect()->route('training.edit-course', $course)
            ->with('success', 'Lesson added.');
    }

    public function editLesson($lessonId)
    {
        $user = auth()->user();
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

        $lesson = TrainingLesson::with('course')->findOrFail($lessonId);

        return view('training.lesson-form', ['course' => $lesson->course, 'lesson' => $lesson]);
    }

    public function updateLesson($lessonId, Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->isOwnerRole() || $user?->effectiveRole() === 'super_admin', 403);

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
            $validated['document_path'] = $request->file('document_file')
                ->store('training/lessons/' . $lesson->course_id, 'public');
        }

        $lesson->update($validated);

        return redirect()->route('training.edit-course', $lesson->course_id)
            ->with('success', 'Lesson updated.');
    }
}
