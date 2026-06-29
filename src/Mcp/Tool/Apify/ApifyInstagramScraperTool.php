<?php

namespace App\Mcp\Tool\Apify;

/**
 * Wraps the general-purpose "apify/instagram-scraper" actor.
 *
 * The Swiss-army knife of Instagram scraping: feed it Instagram URLs
 * (profiles, posts, hashtags, places) and/or a search query, and pick what
 * kind of content to extract (posts, details, comments, reels, mentions).
 *
 * @see https://apify.com/apify/instagram-scraper
 */
class ApifyInstagramScraperTool extends AbstractApifyActorTool
{
    protected function getActorId(): string
    {
        return 'apify/instagram-scraper';
    }

    public function getName(): string
    {
        return 'apify_instagram_scraper';
    }

    public function getDescription(): string
    {
        return 'General-purpose Instagram scraper: scrape posts, profile/hashtag/place details, comments, reels or mentions from Instagram URLs and/or a search query. Via the Apify instagram-scraper actor.';
    }

    protected function getProperties(): array
    {
        return [
            'direct_urls' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Instagram URLs to scrape (profiles, posts, hashtags or places), e.g. ["https://www.instagram.com/natgeo/"].',
            ],
            'results_type' => [
                'type' => 'string',
                'enum' => ['posts', 'details', 'comments', 'reels', 'mentions', 'stories'],
                'description' => 'What to scrape from each URL. Default "posts".',
            ],
            'results_limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of results per URL. Default 200.',
            ],
            'search' => [
                'type' => 'string',
                'description' => 'Search query to look up profiles, hashtags or places instead of (or in addition to) direct_urls.',
            ],
            'search_type' => [
                'type' => 'string',
                'enum' => ['hashtag', 'profile', 'place', 'user'],
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
