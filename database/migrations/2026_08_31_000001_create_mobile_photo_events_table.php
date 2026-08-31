<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photo upload telemetry (Johan, 2026-08-31).
 *
 * Two shoots in four days lost photos that the server never saw, and both times
 * the only way to find out what happened was to reconstruct it afterwards from
 * nginx logs and the idempotency keys that happened to survive:
 *
 *   - 2026-08-28, property 15936: 35 sent, 35 stored — but 27 sat in the app's
 *     queue for 68 minutes and arrived untagged. Looked like data loss; wasn't.
 *   - 2026-08-31, property 15753: ~40 shot, 28 queued, 28 stored. The app's own
 *     counter ran 1..28 with no gaps, which is what proved the missing ~12 were
 *     never enqueued at all — lost between the camera and the queue.
 *
 * That second finding was luck: the counter is an idempotency key, not a
 * diagnostic, and it only told us anything because it happens to be sequential.
 * A photo that dies before the queue leaves NO trace on the server, so "how many
 * did the agent actually take?" is unanswerable. This table makes it answerable.
 *
 * The client reports what it observed (captured / queued / attempted / failed /
 * dropped); the SERVER writes `received` itself in uploadImage(), so arrival is
 * never taken on the client's word. Declared-vs-received is then a subtraction,
 * and the missing ids name themselves along with the last phase they reached.
 *
 * Diagnostic data, deliberately cheap: no soft deletes (there is nothing to
 * recover — a deleted diagnostic row is not a business record), and pruned on a
 * retention window rather than kept forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_photo_events', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('property_id')->index();

            // The per-photo idempotency key the app already generates for the
            // upload itself. Capped at 191 so the composite unique below stays
            // inside InnoDB's index limit under utf8mb4.
            $table->string('client_upload_id', 191);

            // One shoot / one screen session, so a batch can be judged as a
            // whole ("declared 40, landed 28") rather than photo by photo.
            $table->string('batch_id', 191)->nullable()->index();

            // captured | queued | upload_started | upload_ok | upload_failed
            // | dropped | received     (`received` is server-written only)
            $table->string('phase', 32);

            // The PHONE's clock when it happened — the whole point is to see
            // delays between capture and arrival, which server time cannot show.
            $table->timestamp('occurred_at')->nullable();

            // Free-form: error text, attempt number, file size, room_tag, app
            // build, network state. Diagnostics evolve; the column should not.
            $table->json('meta')->nullable();

            $table->timestamps();

            // A replayed batch (the client flushes its local log opportunistically
            // and may resend) must not multiply rows. Scoped by property so two
            // devices cannot collide on a same-millisecond key.
            $table->unique(['property_id', 'client_upload_id', 'phase'], 'uniq_photo_event');

            // The report's own access path: one property's timeline, newest first.
            $table->index(['property_id', 'created_at'], 'idx_photo_events_property_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_photo_events');
    }
};
