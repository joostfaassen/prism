<?php

namespace App\Mcp\Tool\Apify;

/**
 * Wraps the "harvestapi/linkedin-profile-scraper" actor.
 *
 * Extracts detailed information from specific LinkedIn profiles in bulk (work
 * experience, education, skills, …), optionally with email search. Profiles
 * can be given as full URLs, public identifiers, profile IDs or search
 * queries. No cookies/account required.
 *
 * @see https://apify.com/harvestapi/linkedin-profile-scraper
 */
class ApifyLinkedinProfileScraperTool extends AbstractApifyActorTool
{
    protected function getActorId(): string
    {
        return 'harvestapi/linkedin-profile-scraper';
    }

    public function getName(): string
    {
        return 'apify_linkedin_profile_scraper';
    }

    public function getDescription(): string
    {
        return 'Extract detailed information from specific LinkedIn profiles in bulk (work experience, education, skills and more), optionally with email search. Provide profile URLs, public identifiers, profile IDs or search queries. No LinkedIn cookies/account required. Via the Apify harvestapi/linkedin-profile-scraper actor.';
    }

    protected function getProperties(): array
    {
        return [
            'profile_scraper_mode' => [
                'type' => 'string',
                'enum' => ['Profile details no email ($4 per 1k)', 'Profile details + email search ($10 per 1k)'],
                'description' => 'How much to extract; the email-search mode is more expensive. Note the pricing label is part of the value.',
            ],
            'urls' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Full LinkedIn profile URLs, e.g. ["https://www.linkedin.com/in/some-person/"].',
            ],
            'public_identifiers' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Public identifiers (the last part of the profile URL), e.g. ["some-person"].',
            ],
            'profile_ids' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'LinkedIn internal profile IDs.',
            ],
            'queries' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Target search queries to resolve into profiles.',
            ],
        ];
    }

    protected function buildActorInput(array $arguments): array
    {
        $input = [];

        if (isset($arguments['profile_scraper_mode']) && $arguments['profile_scraper_mode'] !== '') {
            $input['profileScraperMode'] = (string) $arguments['profile_scraper_mode'];
        }

        foreach ([
            'urls' => 'urls',
            'public_identifiers' => 'publicIdentifiers',
            'profile_ids' => 'profileIds',
            'queries' => 'queries',
        ] as $arg => $field) {
            $list = $this->toStringList($arguments[$arg] ?? null);
            if ($list !== []) {
                $input[$field] = $list;
            }
        }

        return $input;
    }
}
