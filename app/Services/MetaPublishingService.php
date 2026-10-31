<?php

namespace App\Services;

use App\Models\AgentSocialAccount;
use App\Models\PropertyMarketingPost;
use App\Support\ExternalPublishingGuard;
use App\Support\ExternalPublishingRefused;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MetaPublishingService
{
    private const GRAPH_BASE = 'https://graph.facebook.com/v19.0';
    /** AT-527: video uploads go to the video host. Up to SIMPLE_VIDEO_MAX bytes in one request, bigger files in resumable chunks. */
    private const GRAPH_VIDEO_BASE = 'https://graph-video.facebook.com/v19.0';
    public const SIMPLE_VIDEO_MAX = 50 * 1024 * 1024;

    public function __construct(
        private Client $http = new Client(['timeout' => 60, 'connect_timeout' => 10]),
    ) {}

    /**
     * Publish a post to a Facebook Page.
     * If multiple images, creates a multi-photo post via attached media.
     * Returns the platform post_id.
     */
    public function publishToFacebook(
        AgentSocialAccount $account,
        string $message,
        array $imageUrls,
        ?string $link = null,
    ): string {
        $this->assertPublishingAllowed('Facebook', $account);

        try {
            $pageId    = $account->platform_page_id;
            $pageToken = $account->access_token;

            if (count($imageUrls) > 1) {
                // Multi-photo: upload each image as unpublished photo, then create post
                $attachedMedia = [];
                foreach (array_slice($imageUrls, 0, 10) as $url) {
                    $uploadResp = $this->http->post(self::GRAPH_BASE . '/' . $pageId . '/photos', [
                        'form_params' => [
                            'url'          => $url,
                            'published'    => 'false',
                            'access_token' => $pageToken,
                        ],
                    ]);
                    $photoData       = json_decode($uploadResp->getBody()->getContents(), true);
                    $attachedMedia[] = ['media_fbid' => $photoData['id']];
                }

                $params = [
                    'message'        => $message,
                    'attached_media' => json_encode($attachedMedia),
                    'access_token'   => $pageToken,
                ];
            } elseif (count($imageUrls) === 1) {
                $params = [
                    'message'      => $message,
                    'url'          => $imageUrls[0],
                    'access_token' => $pageToken,
                ];

                if ($link) {
                    $params['link'] = $link;
                }

                $response = $this->http->post(self::GRAPH_BASE . '/' . $pageId . '/photos', [
                    'form_params' => $params,
                ]);

                $data = json_decode($response->getBody()->getContents(), true);

                if (empty($data['post_id']) && empty($data['id'])) {
                    throw new \RuntimeException('Facebook publish: no post_id returned. Response: ' . json_encode($data));
                }

                return $data['post_id'] ?? $data['id'];
            } else {
                $params = [
                    'message'      => $message,
                    'access_token' => $pageToken,
                ];

                if ($link) {
                    $params['link'] = $link;
                }
            }

            $response = $this->http->post(self::GRAPH_BASE . '/' . $pageId . '/feed', [
                'form_params' => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (empty($data['id'])) {
                throw new \RuntimeException('Facebook publish: no post id returned. Response: ' . json_encode($data));
            }

            return $data['id'];
        } catch (\Throwable $e) {
            Log::error('MetaPublishingService::publishToFacebook failed: ' . $e->getMessage(), [
                'account_id' => $account->id,
                'page_id'    => $account->platform_page_id,
            ]);
            throw new \RuntimeException('Failed to publish to Facebook: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * AT-527 (spec .ai/specs/training-videos.md 9.22) - post a VIDEO to a Facebook Page: POST /{page-id}/videos. A file up to SIMPLE_VIDEO_MAX goes in one request
     * ("source"); a bigger one uses Facebook's resumable upload (start -> transfer chunks -> finish). Returns ['id' => the video id, 'dry_run' => bool].
     *
     * When external publishing is OFF on this server (COREX_EXTERNAL_PUBLISHING=false, as on QA1) NOTHING is sent: the call is a DRY RUN that logs exactly what it
     * would have sent (endpoint, page, file, size, title, text - never the token) and returns a 'dry-run-...' id.
     *
     * @return array{id: string, dry_run: bool}
     */
    public function publishVideoToFacebook(AgentSocialAccount $account, string $filePath, string $description, ?string $title = null): array
    {
        $size = is_file($filePath) ? (int) filesize($filePath) : 0;
        if ($size <= 0) {
            throw new \RuntimeException('The video file is missing, so nothing was posted.');
        }
        $pageId = (string) $account->platform_page_id;
        $resumable = $size > self::SIMPLE_VIDEO_MAX;

        if (! ExternalPublishingGuard::isAllowed()) {
            Log::info('DRY RUN (' . ExternalPublishingGuard::refusalReason() . '): would post a video to a Facebook Page - nothing was sent', [
                'endpoint' => self::GRAPH_VIDEO_BASE . '/' . $pageId . '/videos',
                'method' => $resumable ? 'resumable upload (start, transfer chunks, finish)' : 'single upload (source)',
                'page_id' => $pageId, 'page_name' => $account->platform_page_name, 'file' => basename($filePath), 'bytes' => $size,
                'title' => $title, 'description' => $description,
            ]);

            return ['id' => 'dry-run-' . Str::lower(Str::random(12)), 'dry_run' => true];
        }

        try {
            $token = $account->access_token;
            $fields = array_filter(['description' => $description, 'title' => $title], fn ($v) => $v !== null && $v !== '');

            if (! $resumable) {
                $multipart = [['name' => 'access_token', 'contents' => $token], ['name' => 'source', 'contents' => fopen($filePath, 'rb'), 'filename' => basename($filePath)]];
                foreach ($fields as $k => $v) {
                    $multipart[] = ['name' => $k, 'contents' => $v];
                }
                $data = json_decode($this->http->post(self::GRAPH_VIDEO_BASE . '/' . $pageId . '/videos', ['multipart' => $multipart])->getBody()->getContents(), true);
                if (empty($data['id'])) {
                    throw new \RuntimeException('Facebook video publish: no id returned. Response: ' . json_encode($data));
                }

                return ['id' => (string) $data['id'], 'dry_run' => false];
            }

            $url = self::GRAPH_VIDEO_BASE . '/' . $pageId . '/videos';
            $start = json_decode($this->http->post($url, ['form_params' => ['upload_phase' => 'start', 'file_size' => $size, 'access_token' => $token]])->getBody()->getContents(), true);
            $session = $start['upload_session_id'] ?? null;
            $videoId = $start['video_id'] ?? null;
            if (! $session) {
                throw new \RuntimeException('Facebook video publish: could not start the upload. Response: ' . json_encode($start));
            }
            $from = (int) ($start['start_offset'] ?? 0);
            $to = (int) ($start['end_offset'] ?? 0);
            $fh = fopen($filePath, 'rb');
            try {
                while ($to > $from) {
                    fseek($fh, $from);
                    $chunk = (string) fread($fh, $to - $from);
                    $r = json_decode($this->http->post($url, ['multipart' => [
                        ['name' => 'upload_phase', 'contents' => 'transfer'], ['name' => 'start_offset', 'contents' => (string) $from],
                        ['name' => 'upload_session_id', 'contents' => (string) $session], ['name' => 'access_token', 'contents' => $token],
                        ['name' => 'video_file_chunk', 'contents' => $chunk, 'filename' => basename($filePath)],
                    ]])->getBody()->getContents(), true);
                    $from = (int) ($r['start_offset'] ?? $to);
                    $to = (int) ($r['end_offset'] ?? $from);
                }
            } finally {
                fclose($fh);
            }
            $done = json_decode($this->http->post($url, ['form_params' => ['upload_phase' => 'finish', 'upload_session_id' => $session, 'access_token' => $token] + $fields])->getBody()->getContents(), true);
            if (empty($done['success']) && empty($done['id'])) {
                throw new \RuntimeException('Facebook video publish: the upload did not finish. Response: ' . json_encode($done));
            }

            return ['id' => (string) ($videoId ?? $session), 'dry_run' => false];
        } catch (\Throwable $e) {
            Log::error('MetaPublishingService::publishVideoToFacebook failed: ' . $e->getMessage(), ['account_id' => $account->id, 'page_id' => $pageId]);
            throw new \RuntimeException('Failed to post the video to Facebook: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Publish to Instagram using the Content Publish API (container → publish).
     * Returns the media_id.
     */
    public function publishToInstagram(
        AgentSocialAccount $account,
        string $caption,
        array $imageUrls,
    ): string {
        $this->assertPublishingAllowed('Instagram', $account);

        try {
            $igUserId    = $account->platform_page_id;
            $accessToken = $account->access_token;
            $images      = array_slice($imageUrls, 0, 10);

            if (count($images) > 1) {
                // Carousel: create individual containers then a carousel container
                $childIds = [];
                foreach ($images as $url) {
                    $containerResp = $this->http->post(self::GRAPH_BASE . '/' . $igUserId . '/media', [
                        'form_params' => [
                            'image_url'    => $url,
                            'is_carousel_item' => 'true',
                            'access_token' => $accessToken,
                        ],
                    ]);
                    $containerData = json_decode($containerResp->getBody()->getContents(), true);
                    if (empty($containerData['id'])) {
                        throw new \RuntimeException('Instagram carousel item container creation failed: ' . json_encode($containerData));
                    }
                    $childIds[] = $containerData['id'];
                }

                $carouselResp = $this->http->post(self::GRAPH_BASE . '/' . $igUserId . '/media', [
                    'form_params' => [
                        'media_type'   => 'CAROUSEL',
                        'caption'      => $caption,
                        'children'     => implode(',', $childIds),
                        'access_token' => $accessToken,
                    ],
                ]);

                $carouselData = json_decode($carouselResp->getBody()->getContents(), true);
                $containerId  = $carouselData['id'] ?? null;
            } else {
                $imageUrl = $images[0] ?? null;
                if (!$imageUrl) {
                    throw new \RuntimeException('Instagram publish: no image URL provided.');
                }

                $containerResp = $this->http->post(self::GRAPH_BASE . '/' . $igUserId . '/media', [
                    'form_params' => [
                        'image_url'    => $imageUrl,
                        'caption'      => $caption,
                        'access_token' => $accessToken,
                    ],
                ]);

                $containerData = json_decode($containerResp->getBody()->getContents(), true);
                $containerId   = $containerData['id'] ?? null;
            }

            if (!$containerId) {
                throw new \RuntimeException('Instagram publish: failed to create media container.');
            }

            // Publish the container
            $publishResp = $this->http->post(self::GRAPH_BASE . '/' . $igUserId . '/media_publish', [
                'form_params' => [
                    'creation_id'  => $containerId,
                    'access_token' => $accessToken,
                ],
            ]);

            $publishData = json_decode($publishResp->getBody()->getContents(), true);

            if (empty($publishData['id'])) {
                throw new \RuntimeException('Instagram publish: no media_id returned. Response: ' . json_encode($publishData));
            }

            return $publishData['id'];
        } catch (\Throwable $e) {
            Log::error('MetaPublishingService::publishToInstagram failed: ' . $e->getMessage(), [
                'account_id' => $account->id,
            ]);
            throw new \RuntimeException('Failed to publish to Instagram: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Every Meta WRITE goes through publishToFacebook/publishToInstagram, so this is the one place a copy of
     * live is stopped from posting to a real page (ExternalPublishingGuard). Thrown BEFORE any HTTP call and
     * outside the try/catch below, so callers can tell a refusal from a Meta error.
     */
    private function assertPublishingAllowed(string $label, AgentSocialAccount $account): void
    {
        if (ExternalPublishingGuard::isAllowed()) {
            return;
        }

        Log::warning('External publishing refused (meta): ' . $label . ' post for social account ' . $account->id
            . ' did nothing - ' . ExternalPublishingGuard::refusalReason() . '.');

        throw new ExternalPublishingRefused(ExternalPublishingGuard::userMessage());
    }

    /**
     * Fetch post insights (impressions, reach, likes, comments, shares, link_clicks).
     * Returns an array keyed by metric name.
     */
    public function fetchPostInsights(PropertyMarketingPost $post): array
    {
        try {
            $account = $post->socialAccount();
            if (!$account) {
                throw new \RuntimeException('No active social account found for this post.');
            }

            $postId      = $post->platform_post_id;
            $accessToken = $account->access_token;

            if ($post->platform === 'facebook') {
                // Likes/comments/shares are fields on the post object itself
                // (pages_read_engagement). The /insights edge (read_insights)
                // only carries reach-type metrics and rejects the WHOLE request
                // if any metric name is unknown — post_comments / post_shares
                // never existed there, so one combined call always 400'd.
                $object = $this->graphGet($postId, [
                    'fields'       => 'shares,comments.summary(true).limit(0),likes.summary(true).limit(0)',
                    'access_token' => $accessToken,
                ]);

                $insights = $this->graphGet($postId . '/insights', [
                    'metric'       => 'post_impressions,post_impressions_unique,post_clicks',
                    'access_token' => $accessToken,
                ]);

                $byName = [];
                foreach ($insights['data'] ?? [] as $metric) {
                    $byName[$metric['name']] = $metric['values'][0]['value'] ?? 0;
                }

                return [
                    'impressions' => (int) ($byName['post_impressions'] ?? 0),
                    'reach'       => (int) ($byName['post_impressions_unique'] ?? 0),
                    'likes'       => (int) ($object['likes']['summary']['total_count'] ?? 0),
                    'comments'    => (int) ($object['comments']['summary']['total_count'] ?? 0),
                    'shares'      => (int) ($object['shares']['count'] ?? 0),
                    'link_clicks' => (int) ($byName['post_clicks'] ?? 0),
                ];
            } else {
                // Instagram
                $metrics  = 'impressions,reach,likes,comments,shares';
                $data = $this->graphGet($postId . '/insights', [
                    'metric'       => $metrics,
                    'access_token' => $accessToken,
                ], 'Instagram');
                $byName = [];
                foreach ($data['data'] ?? [] as $metric) {
                    $byName[$metric['name']] = $metric['values'][0]['value'] ?? 0;
                }

                return [
                    'impressions' => (int) ($byName['impressions'] ?? 0),
                    'reach'       => (int) ($byName['reach'] ?? 0),
                    'likes'       => (int) ($byName['likes'] ?? 0),
                    'comments'    => (int) ($byName['comments'] ?? 0),
                    'shares'      => (int) ($byName['shares'] ?? 0),
                    'link_clicks' => 0,
                ];
            }
        } catch (\Throwable $e) {
            Log::error('MetaPublishingService::fetchPostInsights failed for post ' . $post->id . ': ' . $e->getMessage());
            throw new \RuntimeException('Failed to fetch post insights: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Guzzle's default 4xx exception text embeds the full request URL — access
     * token included — so it must never reach a log line or the UI.
     */
    private function graphGet(string $path, array $query, string $label = 'Facebook'): array
    {
        $response = $this->http->get(self::GRAPH_BASE . '/' . $path, [
            'query'       => $query,
            'http_errors' => false,
        ]);

        $data = json_decode($response->getBody()->getContents(), true) ?? [];

        if (isset($data['error'])) {
            throw new \RuntimeException($label . ': ' . ($data['error']['message'] ?? 'unknown error'));
        }

        return $data;
    }
}
