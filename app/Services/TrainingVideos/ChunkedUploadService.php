<?php

namespace App\Services\TrainingVideos;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Receives a large video file in pieces and stitches it back together.
 *
 * Spec: .ai/specs/training-videos.md §8 U3, U11.
 *
 * A 180 MB request fails on any server whose PHP limit is lower, and a dropped connection
 * would restart from zero. Pieces avoid both and give the browser a real progress bar. A
 * piece can be re-sent (it overwrites), so a drop never loses more than one piece.
 *
 * Everything lives on the private disk under training-video-uploads/{id}/ and is bound to
 * the user who started it — another user, even with the id, is refused. No video record
 * exists until the owner saves the form after the upload completes.
 */
class ChunkedUploadService
{
    private const ID_PATTERN = '/^[a-f0-9]{32}$/';

    public function __construct(private readonly VideoCompressor $compressor)
    {
    }

    /** @return array{upload_id: string, chunk_bytes: int, total_chunks: int} */
    public function start(User $user, string $fileName, int $size): array
    {
        $max = (int) config('training-videos.max_upload_bytes');
        if ($size < 1) {
            throw ValidationException::withMessages(['file' => 'That file is empty.']);
        }
        if ($size > $max) {
            throw ValidationException::withMessages(['file' => 'That file is larger than ' . $this->mb($max) . '. Shrink it or export a shorter clip.']);
        }

        $chunk = (int) config('training-videos.chunk_bytes');
        $id    = bin2hex(random_bytes(16));

        $this->writeMeta($id, [
            'user_id'      => $user->id,
            'name'         => mb_substr($fileName, 0, 255),
            'size'         => $size,
            'chunk_bytes'  => $chunk,
            'total_chunks' => (int) ceil($size / $chunk),
            'completed'    => false,
        ]);

        return ['upload_id' => $id, 'chunk_bytes' => $chunk, 'total_chunks' => (int) ceil($size / $chunk)];
    }

    /** Store (or re-store) one piece. */
    public function storeChunk(User $user, string $id, int $index, UploadedFile $piece): void
    {
        $meta = $this->meta($user, $id);

        if ($meta['completed']) {
            throw ValidationException::withMessages(['chunk' => 'This upload is already complete.']);
        }
        if ($index < 0 || $index >= $meta['total_chunks']) {
            throw ValidationException::withMessages(['index' => 'That piece number is outside this upload.']);
        }

        $expected = $index === $meta['total_chunks'] - 1
            ? $meta['size'] - $meta['chunk_bytes'] * ($meta['total_chunks'] - 1)
            : $meta['chunk_bytes'];

        if ($piece->getSize() !== $expected) {
            throw ValidationException::withMessages(['chunk' => 'That piece is the wrong size. Send it again.']);
        }

        $dir = $this->dir($id);
        File::ensureDirectoryExists($dir);
        $piece->move($dir, $this->partName($index));
    }

    /**
     * Stitch the pieces, confirm the result is a video CoreX can read, and mark it complete.
     * A file that is not a video is deleted at once and the upload is refused (U4).
     *
     * @return array{upload_id: string, name: string, size: int, duration: int, width: int, height: int}
     */
    public function complete(User $user, string $id): array
    {
        $meta = $this->meta($user, $id);
        @set_time_limit(0);

        if (! $meta['completed']) {
            $missing = [];
            for ($i = 0; $i < $meta['total_chunks']; $i++) {
                if (! is_file($this->dir($id) . '/' . $this->partName($i))) {
                    $missing[] = $i;
                }
            }
            if ($missing !== []) {
                throw ValidationException::withMessages(['upload' => 'Some pieces did not arrive (' . count($missing) . ' missing). Please upload the file again.']);
            }

            $this->stitch($id, $meta['total_chunks']);

            if (filesize($this->assembledPath($id)) !== $meta['size']) {
                $this->delete($id);
                throw ValidationException::withMessages(['upload' => 'The file arrived incomplete. Please upload it again.']);
            }
        }

        try {
            $probe = $this->compressor->probe($this->assembledPath($id));
        } catch (UnreadableVideoException $e) {
            $this->delete($id);
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $this->writeMeta($id, ['completed' => true] + $meta);

        return [
            'upload_id' => $id,
            'name'      => $meta['name'],
            'size'      => $meta['size'],
            'duration'  => $probe['duration'],
            'width'     => $probe['width'],
            'height'    => $probe['height'],
        ];
    }

    /**
     * The finished file for the form save. Refuses an unknown, foreign or unfinished upload.
     *
     * @return array{name: string, size: int}
     */
    public function completedUpload(User $user, string $id): array
    {
        $meta = $this->meta($user, $id);
        if (! $meta['completed'] || ! is_file($this->assembledPath($id))) {
            throw ValidationException::withMessages(['upload_id' => 'The video has not finished uploading.']);
        }

        return ['name' => $meta['name'], 'size' => $meta['size']];
    }

    public function isValidId(mixed $id): bool
    {
        return is_string($id) && preg_match(self::ID_PATTERN, $id) === 1;
    }

    public function assembledPath(string $id): string
    {
        return $this->dir($id) . '/assembled.bin';
    }

    public function exists(string $id): bool
    {
        return $this->isValidId($id) && is_file($this->assembledPath($id));
    }

    public function delete(string $id): void
    {
        if ($this->isValidId($id)) {
            File::deleteDirectory($this->dir($id));
        }
    }

    /**
     * U11: remove uploads untouched for $hours that no video is waiting on.
     *
     * @param  list<string>  $keepIds  uploads a video still references (awaiting or failed compression)
     */
    public function purgeAbandoned(int $hours, array $keepIds): int
    {
        $root = Storage::disk('local')->path(config('training-videos.upload_dir'));
        if (! is_dir($root)) {
            return 0;
        }

        $removed = 0;
        foreach (File::directories($root) as $path) {
            $id = basename($path);
            if (in_array($id, $keepIds, true) || ! $this->isValidId($id)) {
                continue;
            }
            if (filemtime($path) < now()->subHours($hours)->getTimestamp() && $this->newestFileTime($path) < now()->subHours($hours)->getTimestamp()) {
                File::deleteDirectory($path);
                $removed++;
            }
        }

        return $removed;
    }

    // ── Internals ────────────────────────────────────────────────────────

    /** @return array{user_id: int, name: string, size: int, chunk_bytes: int, total_chunks: int, completed: bool} */
    private function meta(User $user, string $id): array
    {
        $file = $this->dir($id) . '/meta.json';
        $meta = $this->isValidId($id) && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (! is_array($meta)) {
            throw new HttpException(404, 'That upload was not found. Start it again.');
        }
        // Another user — even holding a valid id — is refused (spec §8.9 #8).
        if ((int) $meta['user_id'] !== (int) $user->id) {
            throw new HttpException(403, 'That upload belongs to someone else.');
        }

        return $meta;
    }

    private function writeMeta(string $id, array $meta): void
    {
        File::ensureDirectoryExists($this->dir($id));
        file_put_contents($this->dir($id) . '/meta.json', json_encode($meta));
    }

    private function stitch(string $id, int $total): void
    {
        $out = fopen($this->assembledPath($id), 'wb');
        for ($i = 0; $i < $total; $i++) {
            $part = $this->dir($id) . '/' . $this->partName($i);
            $in   = fopen($part, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            unlink($part);
        }
        fclose($out);
    }

    private function dir(string $id): string
    {
        // $id is validated (32 hex) before it reaches any path.
        if (! $this->isValidId($id)) {
            throw new HttpException(404, 'That upload was not found. Start it again.');
        }

        return Storage::disk('local')->path(config('training-videos.upload_dir') . '/' . $id);
    }

    private function partName(int $index): string
    {
        return sprintf('%06d.part', $index);
    }

    private function newestFileTime(string $dir): int
    {
        $newest = 0;
        foreach (File::files($dir) as $f) {
            $newest = max($newest, $f->getMTime());
        }

        return $newest;
    }

    private function mb(int $bytes): string
    {
        return $bytes >= 1073741824 ? round($bytes / 1073741824, 1) . ' GB' : round($bytes / 1048576) . ' MB';
    }
}
