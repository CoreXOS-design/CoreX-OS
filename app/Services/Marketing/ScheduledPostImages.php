<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Models\PropertyMarketingPost;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * AT-456 Part D — the picture files behind scheduled posts.
 *
 * A scheduled post's image is rendered in the browser when the agent presses Schedule, uploaded here,
 * and kept under `marketing-scheduled/{agency}/{user}/` on the public disk (Meta fetches the picture
 * by URL when the post goes out, so it must stay reachable until then). It is kept until the post is
 * published + 30 days (cancelled / failed posts: 30 days after they were last touched); an upload that
 * was never turned into a post is removed after 2 days. purgeExpired() does all of this and is run by
 * the `marketing:purge-scheduled-images` command.
 *
 * Pictures are saved as JPEG (Instagram's publishing API accepts JPEG only) no wider than 1440px
 * (Instagram's limit), flattened onto white.
 */
final class ScheduledPostImages
{
    public const DIR = 'marketing-scheduled';
    public const KEEP_DAYS_AFTER_DONE = 30;
    public const KEEP_DAYS_UNUSED = 2;
    private const MAX_BYTES = 15 * 1024 * 1024;
    private const MAX_WIDTH = 1440;
    /** Decoding a picture costs ~4 bytes per pixel in memory; refuse before decoding anything huge. */
    private const MAX_SIDE = 8000;
    private const MAX_PIXELS = 40_000_000;

    private function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk('public');
    }

    /** Folder a given user's uploads live in. */
    public function folderFor(User $user): string
    {
        return self::DIR . '/' . (int) $user->effectiveAgencyId() . '/' . (int) $user->id;
    }

    /**
     * Save a browser-rendered picture (data URL or bare base64). Returns the storage path.
     *
     * @throws ScheduleException when it is not a usable picture
     */
    public function storeDataUrl(User $user, string $dataUrl): string
    {
        $base64 = preg_replace('/^data:image\/[a-z0-9.+-]+;base64,/i', '', $dataUrl) ?? '';
        $bytes  = base64_decode($base64, true);

        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new ScheduleException('That picture could not be read. Please try again.');
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info[2] ?? 0, [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            throw new ScheduleException('That file is not a picture CoreX can post.');
        }

        // Checked from the header BEFORE decoding: a tiny, highly compressible PNG can claim gigapixels.
        $w = (int) ($info[0] ?? 0);
        $h = (int) ($info[1] ?? 0);
        if ($w < 1 || $h < 1 || $w > self::MAX_SIDE || $h > self::MAX_SIDE || $w * $h > self::MAX_PIXELS) {
            throw new ScheduleException('That picture is too large. Please use one no bigger than 8000 pixels on its longest side.');
        }

        [$out, $ext] = $this->asJpeg($bytes, $info);

        $path = $this->folderFor($user) . '/' . Str::uuid() . '.' . $ext;
        $this->disk()->put($path, $out);

        return $path;
    }

    /** May this user schedule a post with this stored picture? (Their own upload, in their own agency.) */
    public function belongsTo(User $user, string $path): bool
    {
        return str_starts_with($path, $this->folderFor($user) . '/')
            && ! str_contains($path, '..')
            && $this->disk()->exists($path);
    }

    public function urlFor(string $path): string
    {
        return $this->disk()->url($path);
    }

    /** Storage path inside marketing-scheduled/ for a URL we issued, or null for any other URL. */
    public function pathFromUrl(string $url): ?string
    {
        $pos = strpos($url, self::DIR . '/');

        return $pos === false ? null : substr(strtok(substr($url, $pos), '?'), 0);
    }

    /** True when every picture of the post is still on disk. */
    public function allPresent(PropertyMarketingPost $post): bool
    {
        foreach ((array) $post->image_urls as $url) {
            $path = $this->pathFromUrl((string) $url);
            if ($path === null || ! $this->disk()->exists($path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Remove pictures nobody needs any more. Returns ['posts' => files removed for finished posts,
     * 'orphans' => never-used uploads removed].
     *
     * @return array{posts:int, orphans:int}
     */
    public function purgeExpired(): array
    {
        $removed = ['posts' => 0, 'orphans' => 0];
        $cutoff  = now()->subDays(self::KEEP_DAYS_AFTER_DONE);

        // Finished posts: published + 30 days; failed / cancelled: 30 days since last touched.
        PropertyMarketingPost::withoutGlobalScopes()->withTrashed()
            ->where(function ($q) use ($cutoff) {
                $q->where(fn ($w) => $w->where('status', PropertyMarketingPost::STATUS_PUBLISHED)
                        ->where('published_at', '<', $cutoff))
                    ->orWhere(fn ($w) => $w->whereIn('status', [PropertyMarketingPost::STATUS_FAILED, PropertyMarketingPost::STATUS_CANCELLED])
                        ->where('updated_at', '<', $cutoff));
            })
            ->where('image_urls', 'like', '%' . self::DIR . '/%')
            ->orderBy('id')
            ->chunkById(200, function ($posts) use (&$removed) {
                foreach ($posts as $post) {
                    foreach ((array) $post->image_urls as $url) {
                        $path = $this->pathFromUrl((string) $url);
                        if ($path !== null && $this->disk()->exists($path) && ! $this->usedByLivePost($path, (int) $post->id)) {
                            $this->disk()->delete($path);
                            $removed['posts']++;
                        }
                    }
                }
            });

        // Uploads that never became a post.
        $referenced = null;
        foreach ($this->disk()->allFiles(self::DIR) as $path) {
            if ($this->disk()->lastModified($path) > now()->subDays(self::KEEP_DAYS_UNUSED)->getTimestamp()) {
                continue;
            }
            $referenced ??= $this->referencedPaths();
            if (! isset($referenced[$path])) {
                $this->disk()->delete($path);
                $removed['orphans']++;
            }
        }

        return $removed;
    }

    /** Still needed by a post that has not finished (scheduled / publishing) other than $exceptPostId. */
    private function usedByLivePost(string $path, int $exceptPostId): bool
    {
        return PropertyMarketingPost::withoutGlobalScopes()
            ->where('id', '!=', $exceptPostId)
            ->whereIn('status', [PropertyMarketingPost::STATUS_SCHEDULED, PropertyMarketingPost::STATUS_PUBLISHING])
            ->where('image_urls', 'like', '%' . $path . '%')
            ->exists();
    }

    /** @return array<string,true> every stored path any post (even a deleted one) still points at */
    private function referencedPaths(): array
    {
        $paths = [];
        PropertyMarketingPost::withoutGlobalScopes()->withTrashed()
            ->where('image_urls', 'like', '%' . self::DIR . '/%')
            ->select(['id', 'image_urls'])
            ->orderBy('id')
            ->chunkById(500, function ($posts) use (&$paths) {
                foreach ($posts as $post) {
                    foreach ((array) $post->image_urls as $url) {
                        if (($p = $this->pathFromUrl((string) $url)) !== null) {
                            $paths[$p] = true;
                        }
                    }
                }
            });

        return $paths;
    }

    /**
     * @return array{0:string,1:string} [bytes, extension]
     */
    private function asJpeg(string $bytes, array $info): array
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return [$bytes, $info[2] === IMAGETYPE_PNG ? 'png' : ($info[2] === IMAGETYPE_WEBP ? 'webp' : 'jpg')];
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            throw new ScheduleException('That file is not a picture CoreX can post.');
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $nw = min($w, self::MAX_WIDTH);
        $nh = (int) round($h * ($nw / $w));

        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagejpeg($dst, null, 90);
        $out = (string) ob_get_clean();

        return [$out, 'jpg'];
    }
}
