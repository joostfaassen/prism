<?php

namespace App\Integrations\Tmdb;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * TMDb (The Movie Database) client. Read metadata via api_key; rate and
 * watchlist require a user session_id.
 */
class TmdbService
{
    private const API = 'https://api.themoviedb.org/3';
    private const IMG = 'https://image.tmdb.org/t/p';

    /** @var array<string, int> */
    private array $resolvedAccountIds = [];

    public function __construct(
        private readonly TmdbConfigLoader $configLoader,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return list<array{key: string, label: string, language: string, session_configured: bool}>
     */
    public function listAccounts(): array
    {
        $accounts = [];
        foreach ($this->configLoader->getAccounts() as $key => $account) {
            $accounts[] = [
                'key' => $key,
                'label' => $account->label,
                'language' => $account->language,
                'session_configured' => $account->hasSession(),
            ];
        }

        return $accounts;
    }

    /**
     * One-time helper: exchange TMDb username/password for a session_id to
     * paste into prism config. Does not write the config file.
     *
     * @return array{session_id: string, account_id: int, username: string}
     */
    public function createSession(string $username, string $password, ?string $accountKey = null): array
    {
        $username = trim($username);
        $password = trim($password);
        if ($username === '' || $password === '') {
            throw new \InvalidArgumentException('username and password are required');
        }

        $tokenRes = $this->get($accountKey, '/authentication/token/new');
        $requestToken = (string) ($tokenRes['request_token'] ?? '');
        if ($requestToken === '') {
            throw new \RuntimeException('TMDb did not return a request_token');
        }

        $this->post($accountKey, '/authentication/token/validate_with_login', [
            'username' => $username,
            'password' => $password,
            'request_token' => $requestToken,
        ]);

        $sessionRes = $this->post($accountKey, '/authentication/session/new', [
            'request_token' => $requestToken,
        ]);
        $sessionId = (string) ($sessionRes['session_id'] ?? '');
        if ($sessionId === '') {
            throw new \RuntimeException('TMDb did not return a session_id');
        }

        $account = $this->resolveAccount($accountKey);
        $details = $this->get($accountKey, '/account', ['session_id' => $sessionId]);
        $accountId = (int) ($details['id'] ?? 0);
        if ($accountId <= 0) {
            throw new \RuntimeException('TMDb did not return an account id for the new session');
        }

        return [
            'session_id' => $sessionId,
            'account_id' => $accountId,
            'username' => (string) ($details['username'] ?? $username),
            'hint' => sprintf(
                'Add session_id (and optional account_id: %d) under the "%s" tmdb account in your prism.*.yaml, then restart/reload.',
                $accountId,
                $account->key,
            ),
        ];
    }

    /**
     * Rate a movie or TV series (0.5–10 in 0.5 steps).
     *
     * @return array<string, mixed>
     */
    public function rate(
        string $mediaType,
        int $tmdbId,
        float $value,
        ?string $accountKey = null,
    ): array {
        $mediaType = $this->normalizeMediaType($mediaType);
        $this->assertValidRating($value);
        $this->requireSessionAccount($accountKey);

        $path = sprintf('/%s/%d/rating', $mediaType, $tmdbId);
        $status = $this->post($accountKey, $path, ['value' => $value], withSession: true);

        return [
            'ok' => true,
            'media_type' => $mediaType,
            'tmdb_id' => $tmdbId,
            'rating' => $value,
            'tmdb_status' => $status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteRating(string $mediaType, int $tmdbId, ?string $accountKey = null): array
    {
        $mediaType = $this->normalizeMediaType($mediaType);
        $this->requireSessionAccount($accountKey);

        $path = sprintf('/%s/%d/rating', $mediaType, $tmdbId);
        $status = $this->delete($accountKey, $path, withSession: true);

        return [
            'ok' => true,
            'media_type' => $mediaType,
            'tmdb_id' => $tmdbId,
            'tmdb_status' => $status,
        ];
    }

    /**
     * @return array{media_type: string, page: int, total_pages: int, total_results: int, results: list<array<string, mixed>>}
     */
    public function listRated(string $mediaType = 'movie', int $page = 1, ?string $accountKey = null): array
    {
        $mediaType = $this->normalizeMediaType($mediaType);
        $account = $this->requireSessionAccount($accountKey);
        $accountId = $this->resolveTmdbAccountId($accountKey);
        $page = max(1, $page);

        $path = sprintf(
            '/account/%d/rated/%s',
            $accountId,
            $mediaType === 'tv' ? 'tv' : 'movies',
        );
        $res = $this->get($accountKey, $path, [
            'session_id' => $account->sessionId,
            'page' => $page,
            'language' => $account->language,
        ]);

        $results = [];
        foreach ($res['results'] ?? [] as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }
            $results[] = $this->mapAccountListItem($item, $mediaType, includeRating: true);
        }

        return [
            'media_type' => $mediaType,
            'page' => (int) ($res['page'] ?? $page),
            'total_pages' => (int) ($res['total_pages'] ?? 1),
            'total_results' => (int) ($res['total_results'] ?? count($results)),
            'results' => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function setWatchlist(string $mediaType, int $tmdbId, bool $watchlist, ?string $accountKey = null): array
    {
        $mediaType = $this->normalizeMediaType($mediaType);
        $accountId = $this->resolveTmdbAccountId($accountKey);

        $status = $this->post($accountKey, '/account/' . $accountId . '/watchlist', [
            'media_type' => $mediaType,
            'media_id' => $tmdbId,
            'watchlist' => $watchlist,
        ], withSession: true);

        return [
            'ok' => true,
            'media_type' => $mediaType,
            'tmdb_id' => $tmdbId,
            'watchlist' => $watchlist,
            'tmdb_status' => $status,
        ];
    }

    /**
     * @return array{media_type: string, page: int, total_pages: int, total_results: int, results: list<array<string, mixed>>}
     */
    public function listWatchlist(string $mediaType = 'movie', int $page = 1, ?string $accountKey = null): array
    {
        $mediaType = $this->normalizeMediaType($mediaType);
        $account = $this->requireSessionAccount($accountKey);
        $accountId = $this->resolveTmdbAccountId($accountKey);
        $page = max(1, $page);

        $path = sprintf(
            '/account/%d/watchlist/%s',
            $accountId,
            $mediaType === 'tv' ? 'tv' : 'movies',
        );
        $res = $this->get($accountKey, $path, [
            'session_id' => $account->sessionId,
            'page' => $page,
            'language' => $account->language,
        ]);

        $results = [];
        foreach ($res['results'] ?? [] as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }
            $results[] = $this->mapAccountListItem($item, $mediaType, includeRating: false);
        }

        return [
            'media_type' => $mediaType,
            'page' => (int) ($res['page'] ?? $page),
            'total_pages' => (int) ($res['total_pages'] ?? 1),
            'total_results' => (int) ($res['total_results'] ?? count($results)),
            'results' => $results,
        ];
    }

    /**
     * Resolve a normalized record from an IMDb id (tt…). Detects movie vs tv.
     *
     * @return array<string, mixed>
     */
    public function findByImdb(string $imdbId, ?string $accountKey = null, ?string $language = null): array
    {
        $imdbId = trim($imdbId);
        if ($imdbId === '' || !preg_match('/^tt\d+$/', $imdbId)) {
            throw new \InvalidArgumentException('imdb_id must look like tt2798920');
        }

        $res = $this->get($accountKey, '/find/' . rawurlencode($imdbId), [
            'external_source' => 'imdb_id',
            'language' => $language,
        ]);

        if (!empty($res['movie_results'][0]['id'])) {
            return $this->getMovie((int) $res['movie_results'][0]['id'], $accountKey, $language);
        }
        if (!empty($res['tv_results'][0]['id'])) {
            return $this->getTv((int) $res['tv_results'][0]['id'], $accountKey, $language);
        }

        throw new \RuntimeException(sprintf('No TMDb match for IMDb id "%s"', $imdbId));
    }

    /**
     * Search movies or TV and return the top normalized hit plus light results.
     *
     * @return array{result: array<string, mixed>, results: list<array<string, mixed>>}
     */
    public function search(
        string $query,
        ?int $year = null,
        string $kind = 'movie',
        ?string $accountKey = null,
        ?string $language = null,
    ): array {
        $query = trim($query);
        if ($query === '') {
            throw new \InvalidArgumentException('query is required');
        }

        $kind = strtolower($kind);
        if (!in_array($kind, ['movie', 'tv'], true)) {
            throw new \InvalidArgumentException('kind must be "movie" or "tv"');
        }

        $params = ['query' => $query, 'language' => $language];
        if ($year !== null) {
            $params[$kind === 'tv' ? 'first_air_date_year' : 'year'] = $year;
        }

        $res = $this->get($accountKey, $kind === 'tv' ? '/search/tv' : '/search/movie', $params);
        $rawHits = is_array($res['results'] ?? null) ? $res['results'] : [];
        if ($rawHits === []) {
            throw new \RuntimeException(sprintf('No TMDb %s results for "%s"', $kind, $query));
        }

        $light = [];
        foreach (array_slice($rawHits, 0, 10) as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $light[] = [
                'tmdb_id' => isset($hit['id']) ? (int) $hit['id'] : null,
                'title' => $hit['title'] ?? $hit['name'] ?? null,
                'original_title' => $hit['original_title'] ?? $hit['original_name'] ?? null,
                'year' => $this->year($hit['release_date'] ?? $hit['first_air_date'] ?? null),
                'poster_path' => $hit['poster_path'] ?? null,
                'coverUrl' => !empty($hit['poster_path']) ? self::IMG . '/w780' . $hit['poster_path'] : null,
            ];
        }

        $topId = (int) $rawHits[0]['id'];
        $result = $kind === 'tv'
            ? $this->getTv($topId, $accountKey, $language)
            : $this->getMovie($topId, $accountKey, $language);

        return [
            'result' => $result,
            'results' => $light,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMovie(int $tmdbId, ?string $accountKey = null, ?string $language = null): array
    {
        $d = $this->get($accountKey, '/movie/' . $tmdbId, [
            'append_to_response' => 'credits',
            'language' => $language,
        ]);

        $directors = $this->crewByJob($d['credits']['crew'] ?? [], ['Director']);

        return $this->normalize($d, 'movie', $directors, [
            'year' => $this->year($d['release_date'] ?? null),
            'releaseDate' => $d['release_date'] ?? null,
            'runtime' => isset($d['runtime']) && $d['runtime'] ? $d['runtime'] . ' min' : null,
            'title' => $d['title'] ?? null,
            'originalTitle' => $d['original_title'] ?? null,
            'imdb' => $d['imdb_id'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getTv(int $tmdbId, ?string $accountKey = null, ?string $language = null): array
    {
        $d = $this->get($accountKey, '/tv/' . $tmdbId, [
            'append_to_response' => 'credits,external_ids',
            'language' => $language,
        ]);

        $creators = array_values(array_filter(array_map(
            static fn(array $c): string => (string) ($c['name'] ?? ''),
            $d['created_by'] ?? []
        )));
        $seasons = $d['number_of_seasons'] ?? null;

        return $this->normalize($d, 'tv', $creators, [
            'year' => $this->year($d['first_air_date'] ?? null),
            'releaseDate' => $d['first_air_date'] ?? null,
            'runtime' => $seasons ? $seasons . ' season' . ($seasons > 1 ? 's' : '') : null,
            'title' => $d['name'] ?? null,
            'originalTitle' => $d['original_name'] ?? null,
            'imdb' => $d['external_ids']['imdb_id'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $d
     * @param list<string> $creators
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function normalize(array $d, string $kind, array $creators, array $meta): array
    {
        $cast = array_values(array_filter(array_map(
            static fn(array $c): string => (string) ($c['name'] ?? ''),
            array_slice($d['credits']['cast'] ?? [], 0, 6)
        )));
        $genres = array_values(array_filter(array_map(
            static fn(array $g): string => (string) ($g['name'] ?? ''),
            $d['genres'] ?? []
        )));
        $studio = array_values(array_filter(array_map(
            static fn(array $c): string => (string) ($c['name'] ?? ''),
            array_slice($d['production_companies'] ?? [], 0, 4)
        )));

        $country = null;
        if (!empty($d['production_countries'][0]['name'])) {
            $country = (string) $d['production_countries'][0]['name'];
        }
        $language = null;
        if (!empty($d['spoken_languages'][0]['english_name'])) {
            $language = (string) $d['spoken_languages'][0]['english_name'];
        }

        $links = [];
        if (!empty($d['homepage'])) {
            $links[] = ['label' => 'Website', 'url' => (string) $d['homepage']];
        }
        $links[] = ['label' => 'TMDb', 'url' => 'https://www.themoviedb.org/' . $kind . '/' . $d['id']];
        if (!empty($meta['imdb'])) {
            $links[] = ['label' => 'IMDb', 'url' => 'https://www.imdb.com/title/' . $meta['imdb'] . '/'];
        }

        return [
            'source' => 'tmdb',
            'title' => $meta['title'],
            'originalTitle' => $meta['originalTitle'],
            'year' => $meta['year'],
            'releaseDate' => $meta['releaseDate'],
            'synopsis' => $d['overview'] ?? null,
            'creators' => $creators,
            'cast' => $cast,
            'studio' => $studio,
            'genres' => $genres,
            'runtime' => $meta['runtime'],
            'platforms' => [],
            'language' => $language,
            'country' => $country,
            'externalRatings' => array_filter([
                'tmdb' => isset($d['vote_average']) ? round((float) $d['vote_average'], 1) : null,
            ], static fn($v) => $v !== null && $v !== 0.0),
            'externalIds' => array_filter([
                'tmdb' => (string) $d['id'],
                'imdb' => $meta['imdb'] ?? null,
            ]),
            'links' => $links,
            'coverUrl' => !empty($d['poster_path']) ? self::IMG . '/w780' . $d['poster_path'] : null,
            'backdropUrl' => !empty($d['backdrop_path']) ? self::IMG . '/w1280' . $d['backdrop_path'] : null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $crew
     * @param list<string> $jobs
     *
     * @return list<string>
     */
    private function crewByJob(array $crew, array $jobs): array
    {
        $out = [];
        foreach ($crew as $c) {
            if (in_array($c['job'] ?? '', $jobs, true) && !empty($c['name'])) {
                $out[] = (string) $c['name'];
            }
        }

        return array_values(array_unique($out));
    }

    private function year(?string $date): ?int
    {
        if ($date === null || strlen($date) < 4) {
            return null;
        }

        return (int) substr($date, 0, 4);
    }

    private function resolveAccount(?string $accountKey): TmdbAccountConfig
    {
        if ($accountKey !== null && $accountKey !== '') {
            return $this->configLoader->getAccount($accountKey);
        }

        $accounts = $this->configLoader->getAccounts();
        if ($accounts === []) {
            throw new \RuntimeException('No TMDb accounts configured for this server');
        }

        return reset($accounts);
    }

    private function requireSessionAccount(?string $accountKey): TmdbAccountConfig
    {
        $account = $this->resolveAccount($accountKey);
        if (!$account->hasSession()) {
            throw new \RuntimeException(sprintf(
                'TMDb account "%s" has no session_id. Use tmdb_create_session once, then add session_id to the account YAML.',
                $account->key,
            ));
        }

        return $account;
    }

    private function resolveTmdbAccountId(?string $accountKey): int
    {
        $account = $this->requireSessionAccount($accountKey);
        if ($account->accountId !== null && $account->accountId > 0) {
            return $account->accountId;
        }

        if (isset($this->resolvedAccountIds[$account->key])) {
            return $this->resolvedAccountIds[$account->key];
        }

        $details = $this->get($accountKey, '/account', ['session_id' => $account->sessionId]);
        $id = (int) ($details['id'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException(sprintf(
                'Could not resolve TMDb account id for "%s". Set account_id in YAML or recreate the session.',
                $account->key,
            ));
        }

        return $this->resolvedAccountIds[$account->key] = $id;
    }

    private function normalizeMediaType(string $mediaType): string
    {
        $mediaType = strtolower(trim($mediaType));
        if ($mediaType === 'movies') {
            $mediaType = 'movie';
        }
        if ($mediaType === 'series' || $mediaType === 'show' || $mediaType === 'shows') {
            $mediaType = 'tv';
        }
        if (!in_array($mediaType, ['movie', 'tv'], true)) {
            throw new \InvalidArgumentException('media_type must be "movie" or "tv"');
        }

        return $mediaType;
    }

    private function assertValidRating(float $value): void
    {
        if ($value < 0.5 || $value > 10.0) {
            throw new \InvalidArgumentException('rating must be between 0.5 and 10');
        }
        // TMDb accepts 0.5 increments
        $scaled = (int) round($value * 2);
        if (abs($value * 2 - $scaled) > 0.001) {
            throw new \InvalidArgumentException('rating must be in 0.5 steps (e.g. 7, 7.5, 8)');
        }
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function mapAccountListItem(array $item, string $mediaType, bool $includeRating): array
    {
        $row = [
            'media_type' => $mediaType,
            'tmdb_id' => (int) $item['id'],
            'title' => $item['title'] ?? $item['name'] ?? null,
            'original_title' => $item['original_title'] ?? $item['original_name'] ?? null,
            'year' => $this->year($item['release_date'] ?? $item['first_air_date'] ?? null),
            'release_date' => $item['release_date'] ?? $item['first_air_date'] ?? null,
            'overview' => $item['overview'] ?? null,
            'coverUrl' => !empty($item['poster_path']) ? self::IMG . '/w780' . $item['poster_path'] : null,
            'backdropUrl' => !empty($item['backdrop_path']) ? self::IMG . '/w1280' . $item['backdrop_path'] : null,
            'vote_average' => isset($item['vote_average']) ? round((float) $item['vote_average'], 1) : null,
        ];

        if ($includeRating) {
            $row['rating'] = isset($item['rating']) ? (float) $item['rating'] : null;
        }

        return $row;
    }

    /**
     * @param array<string, scalar|null> $params
     *
     * @return array<string, mixed>
     */
    private function get(?string $accountKey, string $path, array $params = []): array
    {
        return $this->request('GET', $accountKey, $path, query: $params);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(?string $accountKey, string $path, array $body = [], bool $withSession = false): array
    {
        return $this->request('POST', $accountKey, $path, body: $body, withSession: $withSession);
    }

    /**
     * @return array<string, mixed>
     */
    private function delete(?string $accountKey, string $path, bool $withSession = false): array
    {
        return $this->request('DELETE', $accountKey, $path, withSession: $withSession);
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        ?string $accountKey,
        string $path,
        array $query = [],
        array $body = [],
        bool $withSession = false,
    ): array {
        $account = $this->resolveAccount($accountKey);

        if ($account->apiKey === '') {
            throw new \RuntimeException(sprintf(
                'TMDb account "%s" is missing api_key',
                $account->key,
            ));
        }

        $params = [];
        foreach ($query as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $params[$name] = $value;
        }
        $params['api_key'] = $account->apiKey;
        if ($withSession) {
            $sessionAccount = $this->requireSessionAccount($accountKey);
            $params['session_id'] = $sessionAccount->sessionId;
        }
        if ($method === 'GET' && !isset($params['language'])) {
            $params['language'] = $account->language;
        }

        $options = [
            'query' => $params,
            'timeout' => 20,
        ];
        if ($method !== 'GET' && $body !== []) {
            $options['json'] = $body;
            $options['headers'] = [
                'Content-Type' => 'application/json;charset=utf-8',
            ];
        }

        $response = $this->httpClient->request($method, self::API . $path, $options);

        $statusCode = $response->getStatusCode();
        $raw = $response->getContent(false);
        $data = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($data)) {
            throw new \RuntimeException('TMDb API returned a non-JSON response');
        }

        if ($statusCode >= 400 || (($data['success'] ?? null) === false)) {
            throw new \RuntimeException(sprintf(
                'TMDb API error (HTTP %d): %s',
                $statusCode,
                $data['status_message'] ?? $raw,
            ));
        }

        return $data;
    }
}
