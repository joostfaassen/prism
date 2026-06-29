<?php

namespace App\Mcp\Tool\Apify;

/**
 * Wraps the "harvestapi/linkedin-profile-search" actor.
 *
 * Searches LinkedIn profiles with a fuzzy query and a rich set of filters,
 * returning detailed profile information — no cookies/account required.
 *
 * The underlying actor exposes a very large set of filters (and MongoDB-based
 * deduplication / segmentation options). This tool exposes the commonly used
 * subset; for advanced/exclusion filters use apify_run_actor against
 * "harvestapi/linkedin-profile-search".
 *
 * @see https://apify.com/harvestapi/linkedin-profile-search
 */
class ApifyLinkedinProfileSearchTool extends AbstractApifyActorTool
{
    protected function getActorId(): string
    {
        return 'harvestapi/linkedin-profile-search';
    }

    public function getName(): string
    {
        return 'apify_linkedin_profile_search';
    }

    public function getDescription(): string
    {
        return 'Search LinkedIn profiles with a fuzzy query and filters (location, company, school, job title, industry, headcount, recently changed jobs/posted) and return detailed profile info. No LinkedIn cookies/account required. Via the Apify harvestapi/linkedin-profile-search actor.';
    }

    protected function getProperties(): array
    {
        return [
            'profile_scraper_mode' => [
                'type' => 'string',
                'enum' => ['Short', 'Full', 'Full + email search'],
                'description' => 'How much profile detail to extract. Default "Full". "Full + email search" also attempts to find emails (more expensive).',
            ],
            'search_query' => [
                'type' => 'string',
                'description' => 'Fuzzy free-text search query.',
            ],
            'max_profiles' => [
                'type' => 'integer',
                'description' => 'Maximum number of profiles to scrape.',
            ],
            'locations' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Location filter.',
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
                'description' => 'Current job title filter.',
            ],
            'past_job_titles' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Past job title filter.',
            ],
            'industry_ids' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'LinkedIn industry ID filter.',
            ],
            'company_headcount' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Company headcount band filter (e.g. ["11-50", "51-200"]).',
            ],
            'recently_changed_jobs' => [
                'type' => 'boolean',
                'description' => 'Only return people who recently changed jobs.',
            ],
            'recently_posted_on_linkedin' => [
                'type' => 'boolean',
                'description' => 'Only return people who recently posted on LinkedIn.',
            ],
        ];
    }

    protected function buildActorInput(array $arguments): array
    {
        $input = [];

        if (isset($arguments['profile_scraper_mode']) && $arguments['profile_scraper_mode'] !== '') {
            $input['profileScraperMode'] = (string) $arguments['profile_scraper_mode'];
        }

        if (isset($arguments['search_query']) && $arguments['search_query'] !== '') {
            $input['searchQuery'] = (string) $arguments['search_query'];
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
            'past_job_titles' => 'pastJobTitles',
            'industry_ids' => 'industryIds',
            'company_headcount' => 'companyHeadcount',
        ] as $arg => $field) {
            $list = $this->toStringList($arguments[$arg] ?? null);
            if ($list !== []) {
                $input[$field] = $list;
            }
        }

        if (array_key_exists('recently_changed_jobs', $arguments)) {
            $input['recentlyChangedJobs'] = (bool) $arguments['recently_changed_jobs'];
        }

        if (array_key_exists('recently_posted_on_linkedin', $arguments)) {
            $input['recentlyPostedOnLinkedIn'] = (bool) $arguments['recently_posted_on_linkedin'];
        }

        return $input;
    }
}
