# Briefing: TMDb + IGDB entertainment tools in Prism

## Doel

Voeg twee nieuwe read-only Prism-integraties toe zodat agents (via kiko-toolbar)
films/series/anime en games kunnen opzoeken — metadata, ratings, en vooral
**cover/poster image URLs** — zonder dat credentials in xa-atlas of Cursor
terechtkomen.

De consumer is Joost's entertainment-library in `xa-atlas`
(`content/entertainment/{id}/index.entertainment.yaml`). Agents daar willen:

1. Een IMDb-id of titel → film/series details + poster URL (TMDb)
2. Een IGDB-slug/id of titel → game details + cover URL (IGDB)
3. Die URLs later downloaden naar `cover.jpg` (download gebeurt in xa-atlas, niet in Prism)

## Lees eerst

- `AGENTS.md` in deze repo (4-staps integration pattern, ToolInterface, profile types)
- Template-integraties: **Matomo** en **SendGrid** (read-only HTTP + JSON)
  - `src/Matomo/` + `src/Mcp/Tool/Matomo*Tool.php`
  - `src/SendGrid/` + `src/Mcp/Tool/SendGrid*Tool.php`
- Scrubbed profile YAML: `prism.config.yaml.example`
- Referentie-HTTP-logica (al werkend in xa-atlas — porten, niet reinventen):
  - `/home/jfaassen/git/joostfaassen/xa-atlas/src/Entertainment/TmdbClient.php`
  - `/home/jfaassen/git/joostfaassen/xa-atlas/src/Entertainment/IgdbClient.php`

## Waarom deze bronnen

| Medium | Canonical id | API | Waarom |
|---|---|---|---|
| film / series / anime / documentary | IMDb `tt…` | **TMDb** | IMDb heeft geen gratis API; TMDb resolve't via `/find/{imdb_id}?external_source=imdb_id` en levert hi-res posters (`image.tmdb.org/t/p/w780/...`) |
| game | IGDB slug of numeric id | **IGDB** (Twitch OAuth) | Covers via `images.igdb.com/.../t_1080p/{image_id}.jpg`, plus genres/platforms/summary |

Wikipedia is géén Prism-tool (keyless, blijft fallback in xa-atlas).

## Profile types & YAML

Voeg toe aan `prism.config.yaml.example` (scrubbed) en aan de live
`prism.kiko.yaml` (of equivalent kiko-server profiles block) — **niet committen**.

```yaml
# TMDb — https://www.themoviedb.org/settings/api (v3 API key)
joost-tmdb:
  type: tmdb
  label: "TMDb (Joost)"
  api_key: "replace-with-tmdb-v3-api-key"
  # optional:
  language: "en-US"   # default en-US; agents may override per call

# IGDB — Twitch app https://dev.twitch.tv/console/apps (client_credentials)
joost-igdb:
  type: igdb
  label: "IGDB (Joost)"
  client_id: "replace-with-twitch-client-id"
  client_secret: "replace-with-twitch-client-secret"
```

Credentials blijven in Prism YAML. Geen Symfony env vars voor deze keys
(tenzij de repo dat elders al zo doet — volg Matomo/SendGrid: YAML).

## Bestanden om te maken (Matomo-patroon)

### TMDb

```
src/Tmdb/TmdbProfileConfig.php
src/Tmdb/TmdbConfigLoader.php
src/Tmdb/TmdbService.php
src/Mcp/Tool/TmdbListProfilesTool.php
src/Mcp/Tool/TmdbFindByImdbTool.php
src/Mcp/Tool/TmdbSearchTool.php
src/Mcp/Tool/TmdbGetMovieTool.php
src/Mcp/Tool/TmdbGetTvTool.php
```

### IGDB

```
src/Igdb/IgdbProfileConfig.php
src/Igdb/IgdbConfigLoader.php
src/Igdb/IgdbService.php
src/Mcp/Tool/IgdbListProfilesTool.php
src/Mcp/Tool/IgdbFindTool.php
src/Mcp/Tool/IgdbSearchTool.php
```

Tools auto-registreren via `ToolInterface` + DI tag (geen handmatige services.yaml
entry nodig, tenzij de repo dat voor vergelijkbare services wél doet — check
Matomo).

## Tool contract (namen = snake_case, prefix = profile type)

Alle tools: **read-only**. `getProfileType()` → `'tmdb'` of `'igdb'`.
`execute()` returnt `['content' => [['type' => 'text', 'text' => json_encode(...)]]]`.

### TMDb tools

| Tool | Input | Output (JSON) |
|---|---|---|
| `tmdb_list_profiles` | (geen / optional) | `[{key, label}]` |
| `tmdb_find_by_imdb` | `imdb_id` (required, `tt…`), optional `profile` | Normalized record (zie hieronder). Detecteert movie vs tv. |
| `tmdb_search` | `query` (required), optional `year`, `kind` (`movie`\|`tv`, default movie), `profile` | Top result as normalized record, plus `results` array of light hits if useful |
| `tmdb_get_movie` | `tmdb_id` (required int), optional `profile` | Normalized movie record |
| `tmdb_get_tv` | `tmdb_id` (required int), optional `profile` | Normalized TV record |

TMDb HTTP (port from xa-atlas `TmdbClient`):
- Base: `https://api.themoviedb.org/3`
- Auth: `api_key` query param (v3) — zelfde als bestaande xa-atlas client
- Find: `GET /find/{imdb_id}?external_source=imdb_id`
- Movie: `GET /movie/{id}?append_to_response=credits`
- TV: `GET /tv/{id}?append_to_response=credits,external_ids`
- Search: `GET /search/movie` or `/search/tv`
- Images: `https://image.tmdb.org/t/p/w780{poster_path}` and `.../w1280{backdrop_path}`

### IGDB tools

| Tool | Input | Output (JSON) |
|---|---|---|
| `igdb_list_profiles` | (geen) | `[{key, label}]` |
| `igdb_find` | `id` (required: numeric IGDB id **or** slug string), optional `profile` | Normalized game record |
| `igdb_search` | `query` (required), optional `profile` | Top result normalized + optional light `results` |

IGDB HTTP (port from xa-atlas `IgdbClient`):
- Token: `POST https://id.twitch.tv/oauth2/token` with `client_id`, `client_secret`, `grant_type=client_credentials`
- Cache token under something like `var/igdb-token-{profileKey}.json` (expires_in) — don't hit Twitch every call
- API: `POST https://api.igdb.com/v4/games` with headers `Client-ID`, `Authorization: Bearer {token}`, body = apicalypse query
- Cover URL: `https://images.igdb.com/igdb/image/upload/t_1080p/{image_id}.jpg`

Apicalypse fields to request (same as xa-atlas):
```
name, summary, storyline, slug, url, first_release_date,
aggregated_rating, rating, genres.name, platforms.name,
involved_companies.company.name, involved_companies.developer,
involved_companies.publisher, cover.image_id,
external_games.category, external_games.uid
```

## Normalized record shape (belangrijk — consumer contract)

Agents mergen dit in `index.entertainment.yaml` public half. Houd dit stabiel:

```json
{
  "source": "tmdb" | "igdb",
  "title": "...",
  "originalTitle": "... or null",
  "year": 2018,
  "releaseDate": "2018-02-23 or null",
  "synopsis": "...",
  "creators": ["Director or developers"],
  "cast": ["..."],
  "studio": ["..."],
  "genres": ["..."],
  "runtime": "115 min or null",
  "platforms": ["PS4", "..."],
  "language": "... or null",
  "country": "... or null",
  "externalRatings": { "tmdb": 6.8 } | { "igdb": 88 },
  "externalIds": { "imdb": "tt2798920", "tmdb": "300668" } | { "igdb": "nier-automata", "steam": "..." },
  "links": [{ "label": "TMDb", "url": "..." }, { "label": "IMDb", "url": "..." }],
  "coverUrl": "https://... absolute URL ready to download ...",
  "backdropUrl": "https://... or null"
}
```

Rules:
- Always return **absolute** `coverUrl` / `backdropUrl` (no relative paths)
- Prefer w780 (TMDb) / t_1080p (IGDB) — consumer scales down
- Never invent personal fields (`rating`, `verdict`, `why`, `moods`, …)
- On miss / HTTP error: return `isError: true` with a clear message
- Optional `profile` arg: if omitted, use the first profile of that type on the server

## Scope / non-goals

- **Do not** download or store image binaries in Prism
- **Do not** write to xa-atlas or any content repo
- **Do not** expose write/mutating TMDb/IGDB endpoints
- **Do not** put real API keys in committed files — only placeholders in `.example`
- Books are out of scope for this PR (later: Open Library)

## Deploy / enable checklist (na implementatie)

1. Profile YAML op de kiko-server zetten met echte keys
2. Prism deployen / herstarten zodat tools zichtbaar zijn op `/mcp/kiko`
3. In **Toolbar** admin: upstream `kiko-prism` → **Pull Tools**
4. Nieuwe tools access policy op **allow** zetten (default na sync is vaak disabled)
5. Melden aan Joost zodat xa-atlas `TOOLS.md` + skill `joostcx--entertainment`
   bijgewerkt kunnen worden naar `kiko-prism__tmdb_*` / `kiko-prism__igdb_*`

## Acceptatiecriteria

- [ ] `tmdb_find_by_imdb` met `tt2798920` (Annihilation) → title + coverUrl + tmdb/imdb ids
- [ ] `tmdb_search` query `Perfect Blue` year 1997 → anime/film hit met poster
- [ ] `igdb_find` id/slug `nier-automata` of search `Disco Elysium` → coverUrl + platforms
- [ ] Zonder profile van dat type op een server: tools niet zichtbaar in `tools/list`
- [ ] Keys alleen in gitignored YAML; example file heeft placeholders
- [ ] Response shape matcht het normalized contract hierboven

## Referentiegedrag (xa-atlas CLI, ter vergelijking)

Na Prism-tools mag xa-atlas agents zo werken:

1. Resolve id (Exa of user geeft `tt…` / igdb slug)
2. Call `kiko-prism__tmdb_find_by_imdb` of `kiko-prism__igdb_find`
3. Merge `fields` into `index.entertainment.yaml` (public half only)
4. `php bin/console entertainment:cover {id} "{coverUrl}"` om lokaal te schalen

Prism levert data; xa-atlas blijft eigenaar van content + cover-bestanden.
