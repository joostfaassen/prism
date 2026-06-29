<?php

namespace App\Mcp\Tool\Apify;

/**
 * Wraps the "harvestapi/linkedin-company-employees" actor.
 *
 * Extracts employees of one or more LinkedIn companies, with filters and
 * detailed profile info (and optional email search) — no cookies/account
 * required.
 *
 * @see https://apify.com/harvestapi/linkedin-company-employees
 */
class ApifyLinkedinCompanyEmployeesTool extends AbstractApifyActorTool
{
    protected function getActorId(): string
    {
        return 'harvestapi/linkedin-company-employees';
    }

    public function getName(): string
    {
        return 'apify_linkedin_company_employees';
    }

    public function getDescription(): string
    {
        return 'Extract employees of one or more LinkedIn companies with filters (location, job title, industry, headcount) and detailed profile info. No LinkedIn cookies/account required. Via the Apify harvestapi/linkedin-company-employees actor.';
    }

    protected function getProperties(): array
    {
        return [
            'companies' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Companies to scrape employees of (company names or LinkedIn company URLs).',
            ],
            'profile_scraper_mode' => [
                'type' => 'string',
                'enum' => ['Short ($4 per 1k)', 'Full ($8 per 1k)', 'Full + email search ($12 per 1k)'],
                'description' => 'How much profile detail to extract. Default "Full ($8 per 1k)". Note the pricing label is part of the value.',
            ],
            'search_query' => [
                'type' => 'string',
                'description' => 'Fuzzy search within employees.',
            ],
            'max_profiles' => [
                'type' => 'integer',
                'description' => 'Maximum number of employee profiles to scrape (total).',
            ],
            'max_items_per_company' => [
                'type' => 'integer',
                'description' => 'Maximum profiles per company (only applies with company_batch_mode "one_by_one").',
            ],
            'company_batch_mode' => [
                'type' => 'string',
                'enum' => ['all_at_once', 'one_by_one'],
                'description' => 'How to process multiple companies. Default "all_at_once".',
            ],
            'job_titles' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Current job title filter.',
            ],
            'past_job_titles' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Past job title filter.',
            ],
            'locations' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Location filter.',
            ],
            'industry_ids' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'LinkedIn industry ID filter.',
            ],
            'company_headcount' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Company headcount band filter.',
            ],
            'recently_changed_jobs' => [
                'type' => 'boolean',
                'description' => 'Only return people who recently changed jobs.',
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

        if (isset($arguments['company_batch_mode']) && $arguments['company_batch_mode'] !== '') {
            $input['companyBatchMode'] = (string) $arguments['company_batch_mode'];
        }

        if (isset($arguments['max_profiles']) && $arguments['max_profiles'] !== '') {
            $input['maxItems'] = (int) $arguments['max_profiles'];
        }

        if (isset($arguments['max_items_per_company']) && $arguments['max_items_per_company'] !== '') {
            $input['maxItemsPerCompany'] = (int) $arguments['max_items_per_company'];
        }

        foreach ([
            'companies' => 'companies',
            'job_titles' => 'jobTitles',
            'past_job_titles' => 'pastJobTitles',
            'locations' => 'locations',
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

        return $input;
    }
}
