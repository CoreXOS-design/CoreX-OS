<?php

namespace App\Services;

use App\Models\AgentSocialAccount;
use App\Models\PropertyMarketingPost;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class MetaPublishingService
{
    private const GRAPH_BASE = 'https://graph.facebook.com/v19.0';

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
     * Publish to Instagram using the Content Publish API (container → publish).
     * Returns the media_id.
     */
    public function publishToInstagram(
        AgentSocialAccount $account,
        string $caption,
        array $imageUrls,
    ): string {
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
                $metrics  = 'post_impressions,post_impressions_unique,post_reactions_like_total,post_comments,post_shares,post_clicks';
                $response = $this->http->get(self::GRAPH_BASE . '/' . $postId . '/insights', [
                    'query' => [
                        'metric'       => $metrics,
                        'access_token' => $accessToken,
                    ],
                ]);

                $data    = json_decode($response->getBody()->getContents(), true);
                $byName  = [];
                foreach ($data['data'] ?? [] as $metric) {
                    $byName[$metric['name']] = $metric['values'][0]['value'] ?? 0;
                }

                return [
                    'impressions' => (int) ($byName['post_impressions'] ?? 0),
                    'reach'       => (int) ($byName['post_impressions_unique'] ?? 0),
                    'likes'       => (int) ($byName['post_reactions_like_total'] ?? 0),
                    'comments'    => (int) ($byName['post_comments'] ?? 0),
                    'shares'      => (int) ($byName['post_shares'] ?? 0),
                    'link_clicks' => (int) ($byName['post_clicks'] ?? 0),
                ];
            } else {
                // Instagram
                $metrics  = 'impressions,reach,likes,comments,shares';
                $response = $this->http->get(self::GRAPH_BASE . '/' . $postId . '/insights', [
                    'query' => [
                        'metric'       => $metrics,
                        'access_token' => $accessToken,
                    ],
                ]);

                $data   = json_decode($response->getBody()->getContents(), true);
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
}
