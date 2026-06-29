<?php

namespace App\Mcp\Tool\Apify;

/**
 * Wraps the "apify/instagram-reel-scraper" actor.
 *
 * Scrapes Instagram reels for a profile or reel URL: caption, timestamp,
 * transcript, hashtags, mentions, tagged users, comments, likes, shares,
 * views and duration.
 *
 * @see https://apify.com/apify/instagram-reel-scraper
 */
class ApifyInstagramReelScraperTool extends AbstractApifyActorTool
{
    protected function getActorId(): string
    {
        return 'apify/instagram-reel-scraper';
    }

    public function getName(): string
    {
        return 'apify_instagram_reel_scraper';
    }

    public function getDescription(): string
    {
        return 'Scrape Instagram reels for one or more profiles (or reel URLs): caption, timestamp, transcript, hashtags, mentions, tagged users, comments, likes, shares, views and duration. Via the Apify instagram-reel-scraper actor.';
    }

    protected function getProperties(): array
    {
        return [
            'username' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Instagram username(s), profile URL(s), ID(s) or reel URL(s), e.g. ["natgeo"].',
            ],
            'results_limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of reels to scrape per profile. Default 10.',
            ],
            'only_posts_newer_than' => [
                'type' => 'string',
                'description' => 'Only return reels newer than this date. Absolute (YYYY-MM-DD) or relative (e.g. "1 week", "3 months").',
            ],
            'skip_pinned_posts' => [
                'type' => 'boolean',
                'description' => 'Skip reels pinned to the top of the profile. Default false.',
            ],
            'include_shares_count' => [
                'type' => 'boolean',
                'description' => 'Also extract the shares count for each reel (slower). Default false.',
            ],
            'include_transcript' => [
                'type' => 'boolean',
                'description' => 'Also extract the audio transcript for each reel (slower). Default false.',
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

        if (isset($arguments['only_posts_newer_than']) && $arguments['only_posts_newer_than'] !== '') {
            $input['onlyPostsNewerThan'] = (string) $arguments['only_posts_newer_than'];
        }

        if (array_key_exists('skip_pinned_posts', $arguments)) {
            $input['skipPinnedPosts'] = (bool) $arguments['skip_pinned_posts'];
        }

        if (array_key_exists('include_shares_count', $arguments)) {
            $input['includeSharesCount'] = (bool) $arguments['include_shares_count'];
        }

        if (array_key_exists('include_transcript', $arguments)) {
            $input['includeTranscript'] = (bool) $arguments['include_transcript'];
        }

        return $input;
    }
}
