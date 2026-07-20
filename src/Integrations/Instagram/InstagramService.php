<?php

namespace App\Integrations\Instagram;

use App\Config\ServerContext;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin client over the Meta Graph API for Instagram professional (Business /
 * Creator) profiles. Covers profile + audience reads, media + insights, comment
 * management, hashtag search, business discovery of other public profiles, and
 * content publishing (photos, videos, reels, stories, carousels).
 */
class InstagramService
{
    private const GRAPH_BASE = 'https://graph.facebook.com';

    /** Default fields returned for the authenticated profile's profile. */
    private const ACCOUNT_FIELDS = 'id,username,name,biography,website,profile_picture_url,followers_count,follows_count,media_count';

    /** Default fields returned per media object. */
    private const MEDIA_FIELDS = 'id,caption,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp,username,like_count,comments_count';

    public function __construct(
        private readonly InstagramConfigLoader $configLoader,
        private readonly InstagramTokenStore $tokenStore,
        private readonly ServerContext $serverContext,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return list<array{key: string, label: string, username: string, ig_user_id: string, configured: bool, can_refresh_token: bool, token_days_left: int|null, api_version: string}>
     */
    public function listProfiles(): array
    {
        $profiles = [];
        foreach ($this->configLoader->getProfiles() as $key => $profile) {
            $profiles[] = [
                'key' => $key,
                'label' => $profile->label,
                'username' => $profile->username,
                'ig_user_id' => $profile->igUserId,
                'configured' => $profile->hasCredentials(),
                'can_refresh_token' => $profile->canRefreshToken(),
                'token_days_left' => $profile->daysUntilExpiry(),
                'api_version' => $profile->apiVersion,
            ];
        }

        return $profiles;
    }

    // ──────────────────────────────────────────────────────────────────
    // Profile & audience
    // ──────────────────────────────────────────────────────────────────

    /**
     * The authenticated profile's own profile (followers, media count, bio, ...).
     *
     * @return array<string, mixed>
     */
    public function getProfile(?string $profileKey, ?string $fields = null): array
    {
        $profile = $this->resolveProfile($profileKey);

        return $this->request($profile, 'GET', $profile->igUserId, [
            'fields' => $fields ?? self::ACCOUNT_FIELDS,
        ]);
    }

    /**
     * Profile-level insights (reach, profile views, follower growth, audience
     * demographics, ...). Metrics, period and modifiers are passed through to the
     * Graph API so new metrics work without code changes.
     *
     * @return array<string, mixed>
     */
    public function getInsights(
        ?string $profileKey,
        string $metric,
        string $period = 'day',
        ?string $metricType = null,
        ?string $breakdown = null,
        ?string $timeframe = null,
        ?int $since = null,
        ?int $until = null,
    ): array {
        $profile = $this->resolveProfile($profileKey);

        return $this->request($profile, 'GET', $profile->igUserId . '/insights', [
            'metric' => $metric,
            'period' => $period,
            'metric_type' => $metricType,
            'breakdown' => $breakdown,
            'timeframe' => $timeframe,
            'since' => $since,
            'until' => $until,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // Media
    // ──────────────────────────────────────────────────────────────────

    /**
     * List the profile's published media with cursor pagination.
     *
     * @return array<string, mixed>
     */
    public function listMedia(
        ?string $profileKey,
        ?string $fields = null,
        int $limit = 25,
        ?string $after = null,
    ): array {
        $profile = $this->resolveProfile($profileKey);

        return $this->request($profile, 'GET', $profile->igUserId . '/media', [
            'fields' => $fields ?? self::MEDIA_FIELDS,
            'limit' => $limit,
            'after' => $after,
        ]);
    }

    /**
     * A single media object, including carousel children when requested.
     *
     * @return array<string, mixed>
     */
    public function getMedia(?string $profileKey, string $mediaId, ?string $fields = null): array
    {
        $profile = $this->resolveProfile($profileKey);
        $fields ??= self::MEDIA_FIELDS . ',children{id,media_type,media_url,thumbnail_url,permalink}';

        return $this->request($profile, 'GET', $mediaId, ['fields' => $fields]);
    }

    /**
     * Insights for a single media object (reach, saved, shares, plays, ...).
     *
     * @return array<string, mixed>
     */
    public function getMediaInsights(?string $profileKey, string $mediaId, string $metric, ?string $breakdown = null): array
    {
        $profile = $this->resolveProfile($profileKey);

        return $this->request($profile, 'GET', $mediaId . '/insights', [
            'metric' => $metric,
            'breakdown' => $breakdown,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // Comments
    // ──────────────────────────────────────────────────────────────────

    /**
     * List comments on a media object (optionally including replies).
     *
     * @return array<string, mixed>
     */
    public function listComments(?string $profileKey, string $mediaId, int $limit = 25, ?string $after = null): array
    {
        $profile = $this->resolveProfile($profileKey);

        return $this->request($profile, 'GET', $mediaId . '/comments', [
            'fields' => 'id,text,username,timestamp,like_count,hidden,replies{id,text,username,timestamp,like_count}',
            'limit' => $limit,
            'after' => $after,
        ]);
    }

    /**
     * Reply to an existing comment (creates a threaded reply).
     *
     * @return array<string, mixed>
     */
    public function replyToComment(?string $profileKey, string $commentId, string $message): array
    {
        $profile = $this->resolveProfile($profileKey);

        return $this->request($profile, 'POST', $commentId . '/replies', ['message' => $message]);
    }

    /**
     * Post a top-level comment on a media object.
     *
     * @return array<string, mixed>
     */
    public function commentOnMedia(?string $profileKey, string $mediaId, string $message): array
    {
        $profile = $this->resolveProfile($profileKey);

        return $this->request($profile, 'POST', $mediaId . '/comments', ['message' => $message]);
    }

    /**
     * Hide or unhide a comment.
     *
     * @return array<string, mixed>
     */
    public function setCommentHidden(?string $profileKey, string $commentId, bool $hide): array
    {
        $profile = $this->resolveProfile($profileKey);

        return $this->request($profile, 'POST', $commentId, ['hide' => $hide]);
    }

    /**
     * Permanently delete a comment.
     *
     * @return array<string, mixed>
     */
    public function deleteComment(?string $profileKey, string $commentId): array
    {
        $profile = $this->resolveProfile($profileKey);

        return $this->request($profile, 'DELETE', $commentId, []);
    }

    // ──────────────────────────────────────────────────────────────────
    // Discovery (hashtags + other profiles)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Resolve a hashtag name to its node id and, optionally, fetch the top or
     * most recent public media for that hashtag — a key building block for
     * finding creators and content to engage with.
     *
     * @param 'top'|'recent'|'none' $media
     *
     * @return array<string, mixed>
     */
    public function hashtagSearch(?string $profileKey, string $hashtag, string $media = 'top', int $limit = 25): array
    {
        $profile = $this->resolveProfile($profileKey);
        $hashtag = ltrim(trim($hashtag), '#');

        $search = $this->request($profile, 'GET', 'ig_hashtag_search', [
            'user_id' => $profile->igUserId,
            'q' => $hashtag,
        ]);

        $hashtagId = $search['data'][0]['id'] ?? null;
        $result = [
            'hashtag' => $hashtag,
            'hashtag_id' => $hashtagId,
        ];

        if ($hashtagId === null || $media === 'none') {
            return $result;
        }

        $edge = $media === 'recent' ? 'recent_media' : 'top_media';
        $result['media_edge'] = $edge;
        $result['media'] = $this->request($profile, 'GET', $hashtagId . '/' . $edge, [
            'user_id' => $profile->igUserId,
            'fields' => 'id,caption,media_type,permalink,like_count,comments_count,timestamp',
            'limit' => $limit,
        ]);

        return $result;
    }

    /**
     * Look up another public professional profile by username (followers, media
     * counts, recent posts). Uses field expansion so a single call can return the
     * target's profile and recent media.
     *
     * @return array<string, mixed>
     */
    public function businessDiscovery(?string $profileKey, string $username, ?string $fields = null, int $mediaLimit = 12): array
    {
        $profile = $this->resolveProfile($profileKey);
        $username = ltrim(trim($username), '@');

        $fields ??= sprintf(
            'username,name,biography,website,profile_picture_url,followers_count,follows_count,media_count,'
            . 'media.limit(%d){id,caption,media_type,permalink,like_count,comments_count,timestamp}',
            $mediaLimit,
        );

        $data = $this->request($profile, 'GET', $profile->igUserId, [
            'fields' => sprintf('business_discovery.username(%s){%s}', $username, $fields),
        ]);

        return $data['business_discovery'] ?? $data;
    }

    // ──────────────────────────────────────────────────────────────────
    // Publishing
    // ──────────────────────────────────────────────────────────────────

    /**
     * Publish content. Handles single image/video/reel/story posts and
     * carousels: it creates the media container(s), waits for any video/reel
     * processing to finish, then publishes — unless `publish` is false (which
     * returns a ready container id for deferred / scheduled publishing) or a
     * `creation_id` is supplied (which publishes an existing container).
     *
     * @param array<string, mixed> $opts
     *
     * @return array<string, mixed>
     */
    public function publish(?string $profileKey, array $opts): array
    {
        $profile = $this->resolveProfile($profileKey);

        $publish = (bool) ($opts['publish'] ?? true);
        $maxWaitSeconds = (int) ($opts['max_wait_seconds'] ?? 90);

        $creationId = isset($opts['creation_id']) ? trim((string) $opts['creation_id']) : '';
        $needsProcessingWait = false;

        if ($creationId !== '') {
            $needsProcessingWait = true;
        } else {
            $mediaType = strtoupper(trim((string) ($opts['media_type'] ?? 'IMAGE')));

            if ($mediaType === 'CAROUSEL') {
                [$creationId, $needsProcessingWait] = $this->createCarouselContainer($profile, $opts);
            } else {
                [$creationId, $needsProcessingWait] = $this->createSingleContainer($profile, $mediaType, $opts);
            }
        }

        $result = [
            'creation_id' => $creationId,
            'published' => false,
        ];

        if (!$publish) {
            $result['status'] = $this->getContainerStatus($profile, $creationId);

            return $result;
        }

        if ($needsProcessingWait) {
            $this->waitForContainer($profile, $creationId, $maxWaitSeconds);
        }

        $published = $this->request($profile, 'POST', $profile->igUserId . '/media_publish', [
            'creation_id' => $creationId,
        ]);

        $result['published'] = true;
        $result['media_id'] = $published['id'] ?? null;

        $mediaId = $published['id'] ?? null;
        if (is_string($mediaId) && $mediaId !== '') {
            try {
                $result['media'] = $this->getMedia($profileKey, $mediaId, self::MEDIA_FIELDS);
            } catch (\Throwable) {
                // Publishing succeeded; enrichment is best-effort.
            }
        }

        return $result;
    }

    /**
     * Current Instagram content-publishing rate-limit usage (max 100 posts / 24h).
     *
     * @return array<string, mixed>
     */
    public function getPublishingLimit(?string $profileKey): array
    {
        $profile = $this->resolveProfile($profileKey);

        return $this->request($profile, 'GET', $profile->igUserId . '/content_publishing_limit', [
            'fields' => 'config,quota_usage',
        ]);
    }

    /**
     * @param array<string, mixed> $opts
     *
     * @return array{0: string, 1: bool} container id and whether it needs a processing wait
     */
    private function createSingleContainer(InstagramProfileConfig $profile, string $mediaType, array $opts): array
    {
        $params = [
            'caption' => $opts['caption'] ?? null,
            'location_id' => $opts['location_id'] ?? null,
            'alt_text' => $opts['alt_text'] ?? null,
        ];

        $isVideo = in_array($mediaType, ['VIDEO', 'REELS'], true)
            || ($mediaType === 'STORIES' && !empty($opts['video_url']));

        if ($mediaType === 'IMAGE' || ($mediaType === 'STORIES' && empty($opts['video_url']))) {
            $params['image_url'] = $opts['image_url'] ?? null;
            if ($mediaType === 'STORIES') {
                $params['media_type'] = 'STORIES';
            }
        } else {
            $params['video_url'] = $opts['video_url'] ?? null;
            $params['media_type'] = $mediaType === 'STORIES' ? 'STORIES' : $mediaType;
            $params['cover_url'] = $opts['cover_url'] ?? null;
            $params['thumb_offset'] = $opts['thumb_offset'] ?? null;
            if ($mediaType === 'REELS') {
                $params['share_to_feed'] = isset($opts['share_to_feed']) ? (bool) $opts['share_to_feed'] : null;
                $params['audio_name'] = $opts['audio_name'] ?? null;
            }
        }

        if (isset($opts['user_tags'])) {
            $params['user_tags'] = is_string($opts['user_tags'])
                ? $opts['user_tags']
                : json_encode($opts['user_tags'], JSON_THROW_ON_ERROR);
        }
        if (isset($opts['collaborators'])) {
            $params['collaborators'] = is_string($opts['collaborators'])
                ? $opts['collaborators']
                : json_encode($opts['collaborators'], JSON_THROW_ON_ERROR);
        }

        $container = $this->request($profile, 'POST', $profile->igUserId . '/media', $params);
        $id = (string) ($container['id'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('Instagram did not return a media container id.');
        }

        return [$id, $isVideo];
    }

    /**
     * @param array<string, mixed> $opts
     *
     * @return array{0: string, 1: bool}
     */
    private function createCarouselContainer(InstagramProfileConfig $profile, array $opts): array
    {
        $items = $opts['children'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new \InvalidArgumentException('A carousel requires a non-empty "children" array of items.');
        }

        $childIds = [];
        $hasVideo = false;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $childParams = ['is_carousel_item' => true];
            $type = strtoupper(trim((string) ($item['media_type'] ?? ($item['video_url'] ?? null ? 'VIDEO' : 'IMAGE'))));

            if ($type === 'VIDEO') {
                $childParams['media_type'] = 'VIDEO';
                $childParams['video_url'] = $item['video_url'] ?? null;
                $hasVideo = true;
            } else {
                $childParams['image_url'] = $item['image_url'] ?? null;
            }

            $child = $this->request($profile, 'POST', $profile->igUserId . '/media', $childParams);
            $childId = (string) ($child['id'] ?? '');
            if ($childId === '') {
                throw new \RuntimeException('Instagram did not return a carousel child container id.');
            }
            $childIds[] = $childId;
        }

        $parent = $this->request($profile, 'POST', $profile->igUserId . '/media', [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $childIds),
            'caption' => $opts['caption'] ?? null,
            'location_id' => $opts['location_id'] ?? null,
        ]);

        $id = (string) ($parent['id'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('Instagram did not return a carousel container id.');
        }

        return [$id, $hasVideo];
    }

    /**
     * @return array<string, mixed>
     */
    private function getContainerStatus(InstagramProfileConfig $profile, string $containerId): array
    {
        return $this->request($profile, 'GET', $containerId, ['fields' => 'status_code,status']);
    }

    private function waitForContainer(InstagramProfileConfig $profile, string $containerId, int $maxWaitSeconds): void
    {
        $deadline = time() + max(5, $maxWaitSeconds);
        $interval = 3;

        while (true) {
            $status = $this->getContainerStatus($profile, $containerId);
            $code = (string) ($status['status_code'] ?? '');

            if ($code === 'FINISHED') {
                return;
            }
            if ($code === 'ERROR' || $code === 'EXPIRED') {
                throw new \RuntimeException(sprintf(
                    'Media container %s failed processing (status: %s). %s',
                    $containerId,
                    $code,
                    (string) ($status['status'] ?? ''),
                ));
            }

            if (time() >= $deadline) {
                throw new \RuntimeException(sprintf(
                    'Media container %s still processing after %ds (status: %s). Re-run publish with creation_id="%s" once ready.',
                    $containerId,
                    $maxWaitSeconds,
                    $code ?: 'UNKNOWN',
                    $containerId,
                ));
            }

            sleep($interval);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Token maintenance
    // ──────────────────────────────────────────────────────────────────

    /**
     * Exchange the current long-lived token for a fresh one (extends ~60 days)
     * and persist it back to the config file. Requires app_id + app_secret.
     *
     * @return array{token_expires_at: int, days_left: int|null}
     */
    public function refreshToken(?string $profileKey): array
    {
        $profile = $this->resolveProfile($profileKey);

        if (!$profile->canRefreshToken()) {
            throw new \RuntimeException(sprintf(
                'Instagram profile "%s" cannot refresh its token: configure app_id and app_secret.',
                $profile->key,
            ));
        }

        $data = $this->request($profile, 'GET', 'oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $profile->appId,
            'client_secret' => $profile->appSecret,
            'fb_exchange_token' => $profile->accessToken,
        ], withAccessToken: false);

        $newToken = (string) ($data['access_token'] ?? '');
        if ($newToken === '') {
            throw new \RuntimeException('Token refresh did not return an access_token.');
        }

        $expiresIn = (int) ($data['expires_in'] ?? 0);
        $expiresAt = $expiresIn > 0 ? time() + $expiresIn : 0;

        $this->tokenStore->persistToken(
            $this->serverContext->getServerName(),
            $profile->key,
            $newToken,
            $expiresAt,
        );

        $updated = $profile->withToken($newToken, $expiresAt);

        return [
            'token_expires_at' => $expiresAt,
            'days_left' => $updated->daysUntilExpiry(),
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Generic escape hatch
    // ──────────────────────────────────────────────────────────────────

    /**
     * Run an arbitrary read-only Graph API GET against any node/edge for
     * advanced queries not covered by the dedicated tools.
     *
     * @param array<string, scalar|null> $params
     *
     * @return array<string, mixed>
     */
    public function rawGet(?string $profileKey, string $path, array $params = []): array
    {
        $profile = $this->resolveProfile($profileKey);

        return $this->request($profile, 'GET', ltrim($path, '/'), $params);
    }

    // ──────────────────────────────────────────────────────────────────
    // Internals
    // ──────────────────────────────────────────────────────────────────

    private function resolveProfile(?string $profileKey): InstagramProfileConfig
    {
        if ($profileKey !== null && $profileKey !== '') {
            $profile = $this->configLoader->getProfile($profileKey);
        } else {
            $profiles = $this->configLoader->getProfiles();
            if ($profiles === []) {
                throw new \RuntimeException('No Instagram profiles configured for this server.');
            }
            $profile = reset($profiles);
        }

        if (!$profile->hasCredentials()) {
            throw new \RuntimeException(sprintf(
                'Instagram profile "%s" is missing ig_user_id and/or access_token in the config.',
                $profile->key,
            ));
        }

        return $profile;
    }

    /**
     * @param array<string, scalar|null> $params
     *
     * @return array<string, mixed>
     */
    private function request(
        InstagramProfileConfig $profile,
        string $method,
        string $path,
        array $params,
        bool $withAccessToken = true,
    ): array {
        $url = self::GRAPH_BASE . '/' . $profile->apiVersion . '/' . $path;

        $payload = [];
        foreach ($params as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $payload[$name] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
        }

        if ($withAccessToken) {
            $payload['access_token'] = $profile->accessToken;
            if ($profile->appSecret !== '') {
                $payload['appsecret_proof'] = hash_hmac('sha256', $profile->accessToken, $profile->appSecret);
            }
        }

        $options = ['timeout' => 60];
        if ($method === 'GET' || $method === 'DELETE') {
            $options['query'] = $payload;
        } else {
            $options['body'] = $payload;
        }

        $response = $this->httpClient->request($method, $url, $options);

        $status = $response->getStatusCode();
        $content = $response->getContent(false);
        $data = $content !== '' ? json_decode($content, true) : [];
        if (!is_array($data)) {
            $data = ['value' => $data];
        }

        if ($status >= 400 || isset($data['error'])) {
            $error = is_array($data['error'] ?? null) ? $data['error'] : [];
            throw new \RuntimeException(sprintf(
                'Instagram Graph API error (HTTP %d): %s%s',
                $status,
                (string) ($error['message'] ?? $content ?: 'unknown error'),
                isset($error['error_user_msg']) ? ' — ' . $error['error_user_msg'] : '',
            ));
        }

        return $data;
    }
}
