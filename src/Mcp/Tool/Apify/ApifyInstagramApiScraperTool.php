<?php

namespace App\Mcp\Tool\Apify;

/**
 * Wraps the "apify/instagram-api-scraper" actor.
 *
 * A fast, login-free Instagram scraper that mirrors the general
 * instagram-scraper inputs: feed it Instagram URLs and/or a search query and
 * pick what to scrape (posts, comments, details, mentions, reels).
 *
 * @see https://apify.com/apify/instagram-api-scraper
 */
class ApifyInstagramApiScraperTool extends AbstractApifyActorTool
{
    protected function getActorId(): string
    {
        return 'apify/instagram-api-scraper';
    }

    public function getName(): string
    {
        return 'apify_instagram_api_scraper';
    }

    public function getDescription(): string
    {
        return 'Fast, login-free Instagram scraper: scrape posts, comments, profile/post/hashtag/place details, mentions or reels from Instagram URLs and/or a search query. Via the Apify instagram-api-scraper actor.';
    }

    protected function getProperties(): array
    {
        return [
            'direct_urls' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Instagram URLs to scrape (profiles, posts, hashtags or places).',
            ],
            'results_type' => [
                'type' => 'string',
                'enum' => ['posts', 'comments', 'details', 'mentions', 'reels', 'stories'],
                'description' => 'What to scrape from each URL. Default "posts".',
            ],
            'results_limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of results per URL. Default 200.',
            ],
            'search' => [
                'type' => 'string',
                'description' => 'Search query to look up users, hashtags or places instead of (or in addition to) direct_urls.',
            ],
            'search_type' => [
                'type' => 'string',
                'enum' => ['user', 'hashtag', 'place'],
                'description' => 'What the search query refers to. Default "hashtag".',
            ],
            'search_limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of search results to process.',
            ],
            'only_posts_newer_than' => [
                'type' => 'string',
                'description' => 'Only return posts newer than this date. Absolute (YYYY-MM-DD) or relative (e.g. "1 week", "3 months").',
            ],
        ];
    }

    protected function buildActorInput(array $arguments): array
    {
        $input = [];

        $urls = $this->toStringList($arguments['direct_urls'] ?? null);
        if ($urls !== []) {
            $input['directUrls'] = $urls;
        }

        if (isset($arguments['results_type']) && $arguments['results_type'] !== '') {
            $input['resultsType'] = (string) $arguments['results_type'];
        }

        if (isset($arguments['results_limit']) && $arguments['results_limit'] !== '') {
            $input['resultsLimit'] = (int) $arguments['results_limit'];
        }

        if (isset($arguments['search']) && $arguments['search'] !== '') {
            $input['search'] = (string) $arguments['search'];
        }

        if (isset($arguments['search_type']) && $arguments['search_type'] !== '') {
            $input['searchType'] = (string) $arguments['search_type'];
        }

        if (isset($arguments['search_limit']) && $arguments['search_limit'] !== '') {
            $input['searchLimit'] = (int) $arguments['search_limit'];
        }

        if (isset($arguments['only_posts_newer_than']) && $arguments['only_posts_newer_than'] !== '') {
            $input['onlyPostsNewerThan'] = (string) $arguments['only_posts_newer_than'];
        }

        return $input;
    }
}
