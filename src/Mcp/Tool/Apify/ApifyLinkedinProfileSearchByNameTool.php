<?php

namespace App\Mcp\Tool\Apify;

/**
 * Wraps the "harvestapi/linkedin-profile-search-by-name" actor.
 *
 * Searches LinkedIn profiles by first/last name (with optional filters) and
 * returns detailed profile info — no cookies or LinkedIn account required.
 *
 * @see https://apify.com/harvestapi/linkedin-profile-search-by-name
 */
class ApifyLinkedinProfileSearchByNameTool extends AbstractApifyActorTool
{
    protected function getActorId(): string
    {
        return 'harvestapi/linkedin-profile-search-by-name';
    }

    public function getName(): string
    {
        return 'apify_linkedin_profile_search_by_name';
    }

    public function getDescription(): string
    {
        return 'Search LinkedIn profiles by first/last name with optional filters (location, company, school, job title, industry) and return detailed profile information. No LinkedIn cookies/account required. Via the Apify harvestapi/linkedin-profile-search-by-name actor.';
    }

    protected function getProperties(): array
    {
        return [
            'profile_scraper_mode' => [
                'type' => 'string',
                'enum' => ['Short', 'Full', 'Full + email search'],
                'description' => 'How much profile detail to extract. "Full + email search" also attempts to find email addresses (more expensive).',
            ],
            'first_name' => [
                'type' => 'string',
                'description' => 'First name to search for.',
            ],
            'last_name' => [
                'type' => 'string',
                'description' => 'Last name to search for.',
            ],
            'strict_search' => [
                'type' => 'boolean',
                'description' => 'Only return exact name matches. Default true.',
            ],
            'max_pages' => [
                'type' => 'integer',
                'description' => 'Maximum number of search result pages to scrape.',
            ],
            'locations' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Location filter, e.g. ["Amsterdam", "Netherlands"].',
            ],
            'current_companies' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Current company filter.',
            ],
            'past_companies' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Past company filter.',
            ],
            'schools' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'School filter.',
            ],
            'current_job_titles' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Current job title filter (exact search).',
            ],
            'industry_ids' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'LinkedIn industry ID filter.',
            ],
            'max_profiles' => [
                'type' => 'integer',
                'description' => 'Maximum number of profiles to scrape.',
            ],
        ];
    }

    protected function getRequired(): array
    {
        return ['profile_scraper_mode'];
    }

    protected function buildActorInput(array $arguments): array
    {
        $input = [
            'profileScraperMode' => (string) ($arguments['profile_scraper_mode'] ?? 'Short'),
        ];

        foreach ([
            'first_name' => 'firstName',
            'last_name' => 'lastName',
        ] as $arg => $field) {
            if (isset($arguments[$arg]) && $arguments[$arg] !== '') {
                $input[$field] = (string) $arguments[$arg];
            }
        }

        if (array_key_exists('strict_search', $arguments)) {
            $input['strictSearch'] = (bool) $arguments['strict_search'];
        }

        if (isset($arguments['max_pages']) && $arguments['max_pages'] !== '') {
            $input['maxPages'] = (int) $arguments['max_pages'];
        }

        if (isset($arguments['max_profiles']) && $arguments['max_profiles'] !== '') {
            $input['maxItems'] = (int) $arguments['max_profiles'];
        }

        foreach ([
            'locations' => 'locations',
            'current_companies' => 'currentCompanies',
            'past_companies' => 'pastCompanies',
            'schools' => 'schools',
            'current_job_titles' => 'currentJobTitles',
            'industry_ids' => 'industryIds',
        ] as $arg => $field) {
            $list = $this->toStringList($arguments[$arg] ?? null);
            if ($list !== []) {
                $input[$field] = $list;
            }
        }

        return $input;
    }
}
