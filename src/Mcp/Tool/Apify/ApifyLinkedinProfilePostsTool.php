<?php

namespace App\Mcp\Tool\Apify;

/**
 * Wraps the "harvestapi/linkedin-profile-posts" actor.
 *
 * Extracts posts from LinkedIn profiles (or company pages): content, media,
 * engagement, and optionally reactions and comments. No cookies/account
 * required.
 *
 * @see https://apify.com/harvestapi/linkedin-profile-posts
 */
class ApifyLinkedinProfilePostsTool extends AbstractApifyActorTool
{
    protected function getActorId(): string
    {
        return 'harvestapi/linkedin-profile-posts';
    }

    public function getName(): string
    {
        return 'apify_linkedin_profile_posts';
    }

    public function getDescription(): string
    {
        return 'Extract posts from LinkedIn profiles or company pages: content, media, engagement, and optionally reactions and comments. No LinkedIn cookies/account required. Via the Apify harvestapi/linkedin-profile-posts actor.';
    }

    protected function getProperties(): array
    {
        return [
            'target_urls' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'LinkedIn profile or company URLs to scrape posts from.',
            ],
            'max_posts' => [
                'type' => 'integer',
                'description' => 'Maximum number of posts to scrape per input URL.',
            ],
            'posted_limit' => [
                'type' => 'string',
                'enum' => ['any', '1h', '24h', 'week', 'month', '3months', '6months', 'year'],
                'description' => 'Only return posts newer than this relative window. Default "any".',
            ],
            'posted_limit_date' => [
                'type' => 'string',
                'description' => 'Only return posts newer than this absolute date (YYYY-MM-DD). Alternative to posted_limit.',
            ],
            'include_quote_posts' => [
                'type' => 'boolean',
                'description' => 'Include quote posts (shared with comments). Default true.',
            ],
            'include_reposts' => [
                'type' => 'boolean',
                'description' => 'Include reposts (shared without comments). Default true.',
            ],
            'scrape_reactions' => [
                'type' => 'boolean',
                'description' => 'Also scrape reactions for each post. Default false.',
            ],
            'max_reactions' => [
                'type' => 'integer',
                'description' => 'Maximum number of reactions to scrape per post.',
            ],
            'scrape_comments' => [
                'type' => 'boolean',
                'description' => 'Also scrape comments for each post. Default false.',
            ],
            'max_comments' => [
                'type' => 'integer',
                'description' => 'Maximum number of comments to scrape per post.',
            ],
            'comments_posted_limit' => [
                'type' => 'string',
                'enum' => ['any', '1h', '24h', 'week', 'month'],
                'description' => 'Only include comments newer than this relative window.',
            ],
        ];
    }

    protected function buildActorInput(array $arguments): array
    {
        $input = [];

        $urls = $this->toStringList($arguments['target_urls'] ?? null);
        if ($urls !== []) {
            $input['targetUrls'] = $urls;
        }

        foreach ([
            'max_posts' => 'maxPosts',
            'max_reactions' => 'maxReactions',
            'max_comments' => 'maxComments',
        ] as $arg => $field) {
            if (isset($arguments[$arg]) && $arguments[$arg] !== '') {
                $input[$field] = (int) $arguments[$arg];
            }
        }

        foreach ([
            'posted_limit' => 'postedLimit',
            'posted_limit_date' => 'postedLimitDate',
            'comments_posted_limit' => 'commentsPostedLimit',
        ] as $arg => $field) {
            if (isset($arguments[$arg]) && $arguments[$arg] !== '') {
                $input[$field] = (string) $arguments[$arg];
            }
        }

        foreach ([
            'include_quote_posts' => 'includeQuotePosts',
            'include_reposts' => 'includeReposts',
            'scrape_reactions' => 'scrapeReactions',
            'scrape_comments' => 'scrapeComments',
        ] as $arg => $field) {
            if (array_key_exists($arg, $arguments)) {
                $input[$field] = (bool) $arguments[$arg];
            }
        }

        return $input;
    }
}
