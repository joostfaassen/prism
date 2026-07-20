<?php

namespace App\Integrations\Igdb;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Read-only IGDB client (Twitch OAuth client_credentials). Returns normalized
 * game records with absolute cover URLs for downstream cover download.
 */
class IgdbService
{
    private const TOKEN_URL = 'https://id.twitch.tv/oauth2/token';
    private const API = 'https://api.igdb.com/v4';
    private const IMG = 'https://images.igdb.com/igdb/image/upload';

    private const FIELDS = 'name, summary, storyline, slug, url, first_release_date, '
        . 'aggregated_rating, rating, genres.name, platforms.name, '
        . 'involved_companies.company.name, involved_companies.developer, '
        . 'involved_companies.publisher, cover.image_id, external_games.category, '
        . 'external_games.uid';

    public function __construct(
        private readonly IgdbConfigLoader $configLoader,
        private readonly HttpClientInterface $httpClient,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function listProfiles(): array
    {
        $profiles = [];
        foreach ($this->configLoader->getProfiles() as $key => $profile) {
            $profiles[] = [
                'key' => $key,
                'label' => $profile->label,
            ];
        }

        return $profiles;
    }

    /**
     * Look up by numeric IGDB id or by slug.
     *
     * @return array<string, mixed>
     */
    public function find(string $idOrSlug, ?string $profileKey = null): array
    {
        $idOrSlug = trim($idOrSlug);
        if ($idOrSlug === '') {
            throw new \InvalidArgumentException('id is required (numeric IGDB id or slug)');
        }

        $where = ctype_digit($idOrSlug)
            ? 'where id = ' . $idOrSlug . ';'
            : 'where slug = "' . addslashes($idOrSlug) . '";';

        $rows = $this->query($profileKey, 'fields ' . self::FIELDS . '; ' . $where . ' limit 1;');
        if ($rows === []) {
            throw new \RuntimeException(sprintf('No IGDB match for "%s"', $idOrSlug));
        }

        return $this->normalize($rows[0]);
    }

    /**
     * Search games and return the top normalized hit plus light results.
     *
     * @return array{result: array<string, mixed>, results: list<array<string, mixed>>}
     */
    public function search(string $query, ?string $profileKey = null): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new \InvalidArgumentException('query is required');
        }

        $rows = $this->query(
            $profileKey,
            'search "' . addslashes($query) . '"; fields ' . self::FIELDS . '; limit 10;',
        );
        if ($rows === []) {
            throw new \RuntimeException(sprintf('No IGDB results for "%s"', $query));
        }

        $light = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $coverUrl = null;
            if (!empty($row['cover']['image_id'])) {
                $coverUrl = self::IMG . '/t_1080p/' . $row['cover']['image_id'] . '.jpg';
            }
            $light[] = [
                'id' => $row['id'] ?? null,
                'slug' => $row['slug'] ?? null,
                'title' => $row['name'] ?? null,
                'year' => !empty($row['first_release_date'])
                    ? (int) date('Y', (int) $row['first_release_date'])
                    : null,
                'coverUrl' => $coverUrl,
            ];
        }

        return [
            'result' => $this->normalize($rows[0]),
            'results' => $light,
        ];
    }

    /**
     * @param array<string, mixed> $g
     *
     * @return array<string, mixed>
     */
    private function normalize(array $g): array
    {
        $genres = array_values(array_filter(array_map(
            static fn(array $x): string => (string) ($x['name'] ?? ''),
            $g['genres'] ?? []
        )));
        $platforms = array_values(array_filter(array_map(
            static fn(array $x): string => (string) ($x['name'] ?? ''),
            $g['platforms'] ?? []
        )));

        $developers = [];
        $publishers = [];
        foreach ($g['involved_companies'] ?? [] as $ic) {
            $name = (string) ($ic['company']['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (!empty($ic['developer'])) {
                $developers[] = $name;
            }
            if (!empty($ic['publisher'])) {
                $publishers[] = $name;
            }
        }
        $studio = array_values(array_unique(array_merge($developers, $publishers)));

        $steam = null;
        foreach ($g['external_games'] ?? [] as $eg) {
            // category 1 = Steam
            if (($eg['category'] ?? null) === 1 && !empty($eg['uid'])) {
                $steam = (string) $eg['uid'];
                break;
            }
        }

        $year = null;
        $releaseDate = null;
        if (!empty($g['first_release_date'])) {
            $year = (int) date('Y', (int) $g['first_release_date']);
            $releaseDate = date('Y-m-d', (int) $g['first_release_date']);
        }

        $links = [];
        if (!empty($g['url'])) {
            $links[] = ['label' => 'IGDB', 'url' => (string) $g['url']];
        }
        if ($steam !== null) {
            $links[] = ['label' => 'Steam', 'url' => 'https://store.steampowered.com/app/' . $steam];
        }

        $coverUrl = null;
        if (!empty($g['cover']['image_id'])) {
            $coverUrl = self::IMG . '/t_1080p/' . $g['cover']['image_id'] . '.jpg';
        }

        return [
            'source' => 'igdb',
            'title' => $g['name'] ?? null,
            'originalTitle' => null,
            'year' => $year,
            'releaseDate' => $releaseDate,
            'synopsis' => $g['summary'] ?? ($g['storyline'] ?? null),
            'creators' => array_values(array_unique($developers)),
            'cast' => [],
            'studio' => $studio,
            'genres' => $genres,
            'runtime' => null,
            'platforms' => $platforms,
            'language' => null,
            'country' => null,
            'externalRatings' => array_filter([
                'igdb' => isset($g['aggregated_rating']) ? (int) round((float) $g['aggregated_rating']) : null,
            ], static fn($v) => $v !== null && $v !== 0),
            'externalIds' => array_filter([
                'igdb' => isset($g['slug']) ? (string) $g['slug'] : (string) ($g['id'] ?? ''),
                'steam' => $steam,
            ]),
            'links' => $links,
            'coverUrl' => $coverUrl,
            'backdropUrl' => null,
        ];
    }

    private function resolveProfile(?string $profileKey): IgdbProfileConfig
    {
        if ($profileKey !== null && $profileKey !== '') {
            return $this->configLoader->getProfile($profileKey);
        }

        $profiles = $this->configLoader->getProfiles();
        if ($profiles === []) {
            throw new \RuntimeException('No IGDB profiles configured for this server');
        }

        return reset($profiles);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function query(?string $profileKey, string $apicalypse): array
    {
        $profile = $this->resolveProfile($profileKey);
        if ($profile->clientId === '' || $profile->clientSecret === '') {
            throw new \RuntimeException(sprintf(
                'IGDB profile "%s" is missing client_id or client_secret',
                $profile->key,
            ));
        }

        $token = $this->accessToken($profile);

        $response = $this->httpClient->request('POST', self::API . '/games', [
            'headers' => [
                'Client-ID' => $profile->clientId,
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
            'body' => $apicalypse,
            'timeout' => 20,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'IGDB API error (HTTP %d): %s',
                $statusCode,
                $response->getContent(false),
            ));
        }

        $data = $response->toArray(false);
        if (!is_array($data)) {
            return [];
        }

        /** @var list<array<string, mixed>> $data */
        return $data;
    }

    private function accessToken(IgdbProfileConfig $profile): string
    {
        $cacheFile = $this->projectDir . '/var/igdb-token-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $profile->key) . '.json';
        if (is_file($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (
                is_array($cached)
                && isset($cached['token'], $cached['expires'])
                && (int) $cached['expires'] > time() + 60
            ) {
                return (string) $cached['token'];
            }
        }

        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'query' => [
                'client_id' => $profile->clientId,
                'client_secret' => $profile->clientSecret,
                'grant_type' => 'client_credentials',
            ],
            'timeout' => 20,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'Twitch OAuth error for IGDB profile "%s" (HTTP %d): %s',
                $profile->key,
                $statusCode,
                $response->getContent(false),
            ));
        }

        $data = $response->toArray(false);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException(sprintf(
                'Twitch OAuth did not return an access_token for IGDB profile "%s"',
                $profile->key,
            ));
        }

        $dir = \dirname($cacheFile);
        if (is_dir($dir) || mkdir($dir, 0775, true) || is_dir($dir)) {
            @file_put_contents($cacheFile, json_encode([
                'token' => $data['access_token'],
                'expires' => time() + (int) ($data['expires_in'] ?? 3600),
            ], JSON_THROW_ON_ERROR));
        }

        return (string) $data['access_token'];
    }
}
