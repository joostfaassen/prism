<?php

namespace App\Mcp\Tool\Apify;

/**
 * Wraps the "apify/instagram-hashtag-scraper" actor.
 *
 * Scrapes posts or reels for one or more hashtags: captions, locations, likes,
 * plays, shares, comment counts, images, timestamps, audio and related
 * hashtags.
 *
 * @see https://apify.com/apify/instagram-hashtag-scraper
 */
class ApifyInstagramHashtagScraperTool extends AbstractApifyActorTool
{
    protected function getActorId(): string
    {
        return 'apify/instagram-hashtag-scraper';
    }

    public function getName(): string
    {
        return 'apify_instagram_hashtag_scraper';
    }

    public function getDescription(): string
    {
        return 'Scrape Instagram posts or reels by hashtag: captions, locations, likes, plays, shares, comment counts, images, timestamps and related hashtags. Via the Apify instagram-hashtag-scraper actor.';
    }

    protected function getProperties(): array
    {
        return [
            'hashtags' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Hashtag(s) to scrape, without the leading "#", e.g. ["travel", "photography"].',
            ],
            'results_type' => [
                'type' => 'string',
                'enum' => ['posts', 'reels', 'stories'],
                'description' => 'Content type to scrape for each hashtag. Default "posts".',
            ],
            'results_limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of posts or reels per hashtag. Default 50.',
            ],
            'keyword_search' => [
                'type' => 'boolean',
                'description' => 'Treat the values as keywords to search for instead of exact hashtags. Default false.',
            ],
        ];
    }

    protected function getRequired(): array
    {
        return ['hashtags'];
    }

    protected function buildActorInput(array $arguments): array
    {
        $input = [
            'hashtags' => $this->toStringList($arguments['hashtags'] ?? null),
        ];

        if (isset($arguments['results_type']) && $arguments['results_type'] !== '') {
            $input['resultsType'] = (string) $arguments['results_type'];
        }

        if (isset($arguments['results_limit']) && $arguments['results_limit'] !== '') {
            $input['resultsLimit'] = (int) $arguments['results_limit'];
        }

        if (array_key_exists('keyword_search', $arguments)) {
            $input['keywordSearch'] = (bool) $arguments['keyword_search'];
        }

        return $input;
    }
}
