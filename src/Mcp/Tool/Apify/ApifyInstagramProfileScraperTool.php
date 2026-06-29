<?php

namespace App\Mcp\Tool\Apify;

/**
 * Wraps the "apify/instagram-profile-scraper" actor.
 *
 * Scrapes Instagram profile info (name, bio, follower/following counts,
 * location, website, post/video counts, latest posts, related profiles) for
 * one or more usernames.
 *
 * @see https://apify.com/apify/instagram-profile-scraper
 */
class ApifyInstagramProfileScraperTool extends AbstractApifyActorTool
{
    protected function getActorId(): string
    {
        return 'apify/instagram-profile-scraper';
    }

    public function getName(): string
    {
        return 'apify_instagram_profile_scraper';
    }

    public function getDescription(): string
    {
        return 'Scrape Instagram profile information for one or more usernames: name, bio, followers/following, location, website, post/video counts, latest posts and related profiles. Via the Apify instagram-profile-scraper actor.';
    }

    protected function getProperties(): array
    {
        return [
            'usernames' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Instagram username(s), ID(s) or profile URL(s) to scrape, e.g. ["humansofny", "natgeo"].',
            ],
            'include_about_section' => [
                'type' => 'boolean',
                'description' => 'Also extract the "About this account" section (former usernames, country, date joined). Default false.',
            ],
        ];
    }

    protected function getRequired(): array
    {
        return ['usernames'];
    }

    protected function buildActorInput(array $arguments): array
    {
        $input = [
            'usernames' => $this->toStringList($arguments['usernames'] ?? null),
        ];

        if (array_key_exists('include_about_section', $arguments)) {
            $input['includeAboutSection'] = (bool) $arguments['include_about_section'];
        }

        return $input;
    }
}
