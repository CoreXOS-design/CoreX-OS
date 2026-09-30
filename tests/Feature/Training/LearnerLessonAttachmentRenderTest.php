<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Models\Agency;
use App\Models\TrainingCourse;
use App\Models\TrainingLesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-30 (Johan, urgent) — the author-facing lesson form
 * (training/lesson-form.blade.php) always lets content, video_url,
 * external_link, and document_file be filled in together, regardless of
 * which content_type is selected. The learner view
 * (training/show.blade.php) used to render them as a mutually-exclusive
 * @if/@elseif chain keyed on content_type, so a document, link, or video
 * attached to a (default) 'text' lesson was saved but never shown to the
 * learner. Proves each attachment now renders independently of
 * content_type, and that a YouTube watch/youtu.be/shorts URL renders as a
 * privacy-enhanced embed while a non-YouTube video_url falls back to a
 * plain link rather than being iframed blind.
 */
final class LearnerLessonAttachmentRenderTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;
    private TrainingCourse $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Learner Co', 'slug' => 'learner-' . uniqid()]);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'agent']);
        $this->course = TrainingCourse::create([
            'agency_id' => $this->agency->id,
            'title' => 'Test Course',
            'category' => 'general',
            'is_published' => true,
        ]);
    }

    private function lesson(array $overrides = []): TrainingLesson
    {
        return TrainingLesson::create(array_merge([
            'course_id' => $this->course->id,
            'title' => 'Test Lesson',
            'content_type' => 'text',
            'sort_order' => 0,
            'is_published' => true,
        ], $overrides));
    }

    public function test_document_attached_to_a_text_lesson_renders_for_the_learner(): void
    {
        $this->lesson([
            'title' => 'Lesson With Document',
            'content' => 'Some text content.',
            'document_path' => 'training/lessons/1/handout.pdf',
        ]);

        $this->actingAs($this->user)
            ->get(route('training.show', $this->course))
            ->assertOk()
            ->assertSee('Download Document', false);
    }

    public function test_external_link_attached_to_a_text_lesson_renders_for_the_learner(): void
    {
        $this->lesson([
            'title' => 'Lesson With Link',
            'content' => 'Some text content.',
            'external_link' => 'https://example.com/resource',
        ]);

        $this->actingAs($this->user)
            ->get(route('training.show', $this->course))
            ->assertOk()
            ->assertSee('https://example.com/resource', false);
    }

    public function test_youtube_watch_url_renders_as_privacy_enhanced_embed(): void
    {
        $this->lesson([
            'title' => 'Lesson With YouTube Watch URL',
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);

        $this->actingAs($this->user)
            ->get(route('training.show', $this->course))
            ->assertOk()
            ->assertSee('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', false);
    }

    public function test_youtu_be_short_url_renders_as_privacy_enhanced_embed(): void
    {
        $this->lesson([
            'title' => 'Lesson With youtu.be URL',
            'video_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);

        $this->actingAs($this->user)
            ->get(route('training.show', $this->course))
            ->assertOk()
            ->assertSee('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', false);
    }

    public function test_youtube_shorts_url_renders_as_privacy_enhanced_embed(): void
    {
        $this->lesson([
            'title' => 'Lesson With Shorts URL',
            'video_url' => 'https://www.youtube.com/shorts/dQw4w9WgXcQ',
        ]);

        $this->actingAs($this->user)
            ->get(route('training.show', $this->course))
            ->assertOk()
            ->assertSee('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', false);
    }

    public function test_non_youtube_video_url_falls_back_to_a_plain_link_not_a_blind_iframe(): void
    {
        $this->lesson([
            'title' => 'Lesson With Non-YouTube Video',
            'video_url' => 'https://player.vimeo.com/video/12345',
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('training.show', $this->course))
            ->assertOk()
            ->assertSee('Watch Video', false);

        $response->assertDontSee('<iframe src="https://player.vimeo.com/video/12345"', false);
    }

    public function test_all_four_attachment_types_render_together_on_one_lesson(): void
    {
        $this->lesson([
            'title' => 'Kitchen Sink Lesson',
            'content' => 'Intro text before the video.',
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'document_path' => 'training/lessons/1/handout.pdf',
            'external_link' => 'https://example.com/resource',
        ]);

        $this->actingAs($this->user)
            ->get(route('training.show', $this->course))
            ->assertOk()
            ->assertSee('Intro text before the video.', false)
            ->assertSee('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', false)
            ->assertSee('Download Document', false)
            ->assertSee('https://example.com/resource', false);
    }
}
