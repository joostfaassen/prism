<?php

namespace App\Mcp\Tool\Apify;

/**
 * Wraps the "apify/instagram-comment-scraper" actor.
 *
 * Scrapes comments from Instagram posts or reels: comment text, post/comment
 * IDs, replies, timestamps, owner IDs, usernames and profile pictures.
 *
 * @see https://apify.com/apify/instagram-comment-scraper
 */
class ApifyInstagramCommentScraperTool extends AbstractApifyActorTool
{
    protected function getActorId(): string
    {
        return 'apify/instagram-comment-scraper';
    }

    public function getName(): string
    {
        return 'apify_instagram_comment_scraper';
    }

    public function getDescription(): string
    {
        return 'Scrape comments from Instagram posts or reels: comment text, post/comment IDs, replies, timestamps, owner IDs, usernames and profile pictures. Via the Apify instagram-comment-scraper actor.';
    }

    protected function getProperties(): array
    {
        return [
            'direct_urls' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Instagram post or reel URL(s) to scrape comments from, e.g. ["https://www.instagram.com/p/Cabc123/"].',
            ],
            'results_limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of comments to scrape per post/reel. Default 20.',
            ],
            'include_nested_comments' => [
                'type' => 'boolean',
                'description' => 'Also include replies (nested comments). Default false.',
            ],
        ];
    }

    protected function getRequired(): array
    {
        return ['direct_urls'];
    }

    protected function buildActorInput(array $arguments): array
    {
        $input = [
            'directUrls' => $this->toStringList($arguments['direct_urls'] ?? null),
        ];

        if (isset($arguments['results_limit']) && $arguments['results_limit'] !== '') {
            $input['resultsLimit'] = (int) $arguments['results_limit'];
        }

        if (array_key_exists('include_nested_comments', $arguments)) {
            $input['includeNestedComments'] = (bool) $arguments['include_nested_comments'];
        }

        return $input;
    }
}
