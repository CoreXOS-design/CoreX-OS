<?php

namespace App\Services\Communications;

use App\Models\Communications\CommunicationAttachment;
use Illuminate\Support\Facades\Log;

/**
 * AT-148 media retry/recovery — turns a pending media attachment into a stored
 * (playable) one, or marks it terminally 'failed'. A media row must NEVER sit on
 * "processing" forever.
 *
 * Recovery re-requests the media from WAHA (the GOWS decrypted-media store is
 * short-lived, so the URL captured at ingest can 404 later), rewrites the
 * container-internal host to the reachable base URL (WahaMediaClient), downloads
 * the bytes, and stores them on the CoreX volume. On failure it bumps
 * retry_count; past the configured max it flips the row to MEDIA_FAILED, which
 * the archive surfaces with a Retry affordance.
 */
class WaMediaRecoveryService
{
    public function __construct(
        private WahaSessionClient $session,
        private WahaMediaClient $media,
        private CommunicationStorageService $storage,
    ) {
    }

    /** @return bool true when the attachment is now stored/playable. */
    public function recover(CommunicationAttachment $att): bool
    {
        if ($att->media_status === CommunicationAttachment::MEDIA_STORED && ! empty($att->storage_path)) {
            return true; // already recovered
        }

        $ref = (string) $att->remote_ref;
        [$sessionName, $filename] = $this->parseRef($ref);
        // AT-168 Part A — address WAHA by the RAW chat id (wa_chat_id), not the
        // now-canonical thread_key. Fall back to thread_key for any legacy row not
        // yet carrying wa_chat_id (pre-migration).
        $chat = (string) ($att->communication->wa_chat_id ?: ($att->communication->thread_key ?? ''));

        try {
            $url = $ref;
            // Re-request from WAHA to regenerate the (short-lived) file + fresh url.
            if ($sessionName !== '' && $chat !== '' && $filename !== '') {
                $fresh = $this->session->regenerateMediaUrl($sessionName, $chat, $filename);
                if (is_string($fresh) && $fresh !== '') {
                    $url = $fresh;
                }
            }
            if ($url === '') {
                throw new \RuntimeException('no media url to fetch');
            }

            $dl = $this->media->download($url); // host-rewritten + SSRF-guarded inside
            $stored = $this->storage->store((int) $att->agency_id, 'attachment', $dl['bytes']);

            $att->forceFill([
                'media_status'     => CommunicationAttachment::MEDIA_STORED,
                'storage_path'     => $stored['path'],
                'content_hash'     => $stored['content_hash'],
                'size_bytes'       => strlen($dl['bytes']),
                'mime'             => $att->mime ?: $dl['mime'],
                'last_media_error' => null,
            ])->save();

            return true;
        } catch (\Throwable $e) {
            $max = max(1, (int) config('communications.waha.media_max_retries', 3));
            $att->retry_count = (int) $att->retry_count + 1;
            $att->last_media_error = mb_substr($e->getMessage(), 0, 500);
            $att->media_status = $att->retry_count >= $max
                ? CommunicationAttachment::MEDIA_FAILED
                : CommunicationAttachment::MEDIA_PENDING;
            $att->save();

            Log::warning('AT-148 media recovery failed', [
                'attachment_id' => $att->id,
                'retry_count'   => $att->retry_count,
                'media_status'  => $att->media_status,
                'error'         => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** Extract [session, filename] from a WAHA files url `…/api/files/{session}/{file}`. */
    private function parseRef(string $ref): array
    {
        if (preg_match('~/api/files/([^/]+)/([^/?#]+)~', $ref, $m)) {
            return [$m[1], $m[2]];
        }

        return ['', ''];
    }
}
