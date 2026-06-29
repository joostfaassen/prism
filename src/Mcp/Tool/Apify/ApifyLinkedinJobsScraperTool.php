<?php

namespace App\Mcp\Tool\Apify;

/**
 * Wraps the "curious_coder/linkedin-jobs-scraper" actor.
 *
 * Scrapes jobs from LinkedIn job-search result URLs, optionally with company
 * details, to gather job listings and contact information.
 *
 * @see https://apify.com/curious_coder/linkedin-jobs-scraper
 */
class ApifyLinkedinJobsScraperTool extends AbstractApifyActorTool
{
    protected function getActorId(): string
    {
        return 'curious_coder/linkedin-jobs-scraper';
    }

    public function getName(): string
    {
        return 'apify_linkedin_jobs_scraper';
    }

    public function getDescription(): string
    {
        return 'Scrape jobs from LinkedIn job-search result URLs, optionally including company details. Provide one or more LinkedIn jobs search URLs. Via the Apify curious_coder/linkedin-jobs-scraper actor.';
    }

    protected function getProperties(): array
    {
        return [
            'urls' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'LinkedIn jobs search result URLs to scrape, e.g. ["https://www.linkedin.com/jobs/search/?keywords=php&location=Netherlands"].',
            ],
            'count' => [
                'type' => 'integer',
                'description' => 'Number of jobs to scrape.',
            ],
            'scrape_company' => [
                'type' => 'boolean',
                'description' => 'Also scrape company details for each job. Default true.',
            ],
            'split_by_location' => [
                'type' => 'boolean',
                'description' => 'Split the search by city locations within the chosen country. Default false.',
            ],
            'split_country' => [
                'type' => 'string',
                'description' => 'ISO 3166-1 alpha-2 country code to split by (e.g. "US", "NL", "GB"). Only used when split_by_location is true.',
            ],
        ];
    }

    protected function getRequired(): array
    {
        return ['urls'];
    }

    protected function buildActorInput(array $arguments): array
    {
        $input = [
            'urls' => $this->toStringList($arguments['urls'] ?? null),
        ];

        if (isset($arguments['count']) && $arguments['count'] !== '') {
            $input['count'] = (int) $arguments['count'];
        }

        if (array_key_exists('scrape_company', $arguments)) {
            $input['scrapeCompany'] = (bool) $arguments['scrape_company'];
        }

        if (array_key_exists('split_by_location', $arguments)) {
            $input['splitByLocation'] = (bool) $arguments['split_by_location'];
        }

        if (isset($arguments['split_country']) && $arguments['split_country'] !== '') {
            $input['splitCountry'] = (string) $arguments['split_country'];
        }

        return $input;
    }
}
