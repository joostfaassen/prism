<?php

namespace App\Mcp\Tool\Apify;

/**
 * Wraps the "apify/instagram-post-scraper" actor.
 *
 * Extracts posts for one or more Instagram profiles (or post/profile URLs):
 * caption, metrics, images, mentions, co-authors, recent comments, sponsored
 * status, video duration, and more.
 *
 * @see https://apify.com/apify/instagram-post-scraper
 */
class ApifyInstagramPostScraperTool extends AbstractApifyActorTool
{
    protected function getActorId(): string
    {
        return 'apify/instagram-post-scraper';
    }

    public function getName(): string
    {
        return 'apify_instagram_post_scraper';
    }

    public function getDescription(): string
    {
        return 'Scrape Instagram posts for one or more usernames (or profile/post URLs): caption, metrics, images, mentions, co-authors, recent comments, sponsored status and video duration. Via the Apify instagram-post-scraper actor.';
    }

    protected function getProperties(): array
    {
        return [
            'username' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Instagram username(s), profile URL(s) or post URL(s), e.g. ["natgeo"].',
            ],
            'results_limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of posts to scrape per profile. Default 10.',
            ],
            'skip_pinned_posts' => [
                'type' => 'boolean',
                'description' => 'Skip posts pinned to the top of the profile. Default false.',
            ],
            'only_posts_newer_than' => [
                'type' => 'string',
                'description' => 'Only return posts newer than this date. Absolute (YYYY-MM-DD) or relative (e.g. "1 week", "3 months").',
            ],
            'data_detail_level' => [
                'type' => 'string',
                'enum' => ['basicData', 'detailedData'],
                'description' => 'Level of detail per post. Default "detailedData".',
            ],
        ];
    }

    protected function getRequired(): array
    {
        return ['username'];
    }

    protected function buildActorInput(array $arguments): array
    {
        $input = [
            'username' => $this->toStringList($arguments['username'] ?? null),
        ];

        if (isset($arguments['results_limit']) && $arguments['results_limit'] !== '') {
            $input['resultsLimit'] = (int) $arguments['results_limit'];
        }

        if (array_key_exists('skip_pinned_posts', $arguments)) {
            $input['skipPinnedPosts'] = (bool) $arguments['skip_pinned_posts'];
        }

        if (isset($arguments['only_posts_newer_than']) && $arguments['only_posts_newer_than'] !== '') {
            $input['onlyPostsNewerThan'] = (string) $arguments['only_posts_newer_than'];
        }

        if (isset($arguments['data_detail_level']) && $arguments['data_detail_level'] !== '') {
            $input['dataDetailLevel'] = (string) $arguments['data_detail_level'];
        }

        return $input;
    }
}
