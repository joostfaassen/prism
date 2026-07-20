<?php

namespace App\Integrations\Tmdb;

use App\Integrations\IntegrationInterface;

class TmdbIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'tmdb';
    }

    public function getLabel(): string
    {
        return 'TMDb';
    }

    public function getDescription(): string
    {
        return 'The Movie Database — resolve IMDb ids, search movies/TV, fetch details, rate titles and manage the watchlist.';
    }
}
