# Instagram Integration

Prism's Instagram integration turns an AI client into a full **marketing-automation cockpit** for Instagram *professional* (Business / Creator) accounts. It can read your page info and audience, analyse engagement, research competitors and niches by hashtag, moderate and reply to comments, and publish photos, videos, reels, stories and carousels — all over the official [Meta Graph API](https://developers.facebook.com/docs/instagram-platform).

> **Professional accounts only.** The Instagram API does not work with personal accounts. You need a Business or Creator account linked to a Facebook Page. Personal-account read access (the old Basic Display API) was deprecated by Meta in December 2024.

## What you can build with it

- **Grow followers** — discover creators and trending posts in your niche (`instagram_hashtag_search`), profile competitors (`instagram_business_discovery`), then engage authentically.
- **Increase engagement** — triage incoming comments (`instagram_list_comments`), reply in‑thread or start conversations (`instagram_reply_comment`), and keep the section clean (`instagram_manage_comment`).
- **Publish at scale** — push reels, posts, stories and carousels (`instagram_publish`), with deferred/scheduled publishing via container IDs and a 24h rate‑limit guard (`instagram_get_publishing_limit`).
- **Measure & optimise** — pull account growth and audience demographics (`instagram_get_insights`) and per‑post performance (`instagram_get_media_insights`).

Combine these with Prism's other integrations (e.g. Canva to generate creatives, Matomo/SendGrid for cross‑channel analytics) for end‑to‑end marketing automation.

## 1. Prerequisites

| Requirement | Notes |
|---|---|
| Instagram **Business** or **Creator** account | Convert in the Instagram app: Settings → Account type and tools |
| A **Facebook Page** linked to the IG account | Required by the Graph API authentication model |
| A **Meta app** | Create at <https://developers.facebook.com/apps> |

In the Meta app, add the **Instagram** product (Instagram API with Facebook Login / Facebook Login for Business).

## 2. Permissions (scopes)

When you generate a token, request these permissions:

| Scope | Enables |
|---|---|
| `instagram_basic` | Profile + media reads |
| `instagram_manage_insights` | Account + media insights, business discovery, hashtag search |
| `instagram_manage_comments` | List / reply / hide / delete comments |
| `instagram_content_publish` | Publish posts, reels, stories, carousels |
| `pages_show_list` | Find the linked Page / IG user id |
| `pages_read_engagement` | Page-linked reads |
| `business_management` | Business asset access |

## 3. Get your `ig_user_id` and a long-lived token

The fastest route is the **Graph API Explorer** (<https://developers.facebook.com/tools/explorer>):

1. Select your app and click **Generate Access Token**, granting the scopes above.
2. Find your Instagram user id:

   ```
   GET me/accounts?fields=instagram_business_account{id,username}
   ```

   Copy `instagram_business_account.id` → this is your `ig_user_id`.
3. Exchange the short-lived token for a **long-lived** one (valid ~60 days):

   ```
   GET https://graph.facebook.com/v22.0/oauth/access_token
       ?grant_type=fb_exchange_token
       &client_id=APP_ID
       &client_secret=APP_SECRET
       &fb_exchange_token=SHORT_LIVED_TOKEN
   ```

   The returned `access_token` is what you put in the config.

> Find `APP_ID` / `APP_SECRET` under your Meta app → **App settings → Basic**.

## 4. Configure in `prism.config.yaml`

Add an `instagram` account to any server:

```yaml
servers:
  marketing-server:
    label: "Marketing"
    bearer_token: "your-prism-bearer-token"
    accounts:
      brand-instagram:
        type: instagram
        label: "Brand IG"
        ig_user_id: "1784xxxxxxxxxxxxx"
        access_token: "your-long-lived-access-token"
        app_id: "your-meta-app-id"
        app_secret: "your-meta-app-secret"
        username: "your_brand"        # optional, informational
        # api_version: "v22.0"        # optional, defaults to v22.0
        token_expires_at: 0           # managed by instagram_refresh_token
```

`app_id` / `app_secret` are optional but recommended: they let Prism send `appsecret_proof` on every request (more secure) and power `instagram_refresh_token`.

### Multiple brands

Add one `instagram` account per brand. Every tool takes an `account` parameter so the AI client can target the right one:

```yaml
accounts:
  brand-a-instagram:
    type: instagram
    label: "Brand A"
    ig_user_id: "1784..."
    access_token: "token-a"
    app_id: "app-id"
    app_secret: "app-secret"
  brand-b-instagram:
    type: instagram
    label: "Brand B"
    ig_user_id: "1784..."
    access_token: "token-b"
    app_id: "app-id"
    app_secret: "app-secret"
```

## 5. Keep the token alive

Long-lived tokens expire after ~60 days. Run **`instagram_refresh_token`** periodically (e.g. weekly) — it exchanges the current token for a fresh one and writes it (and `token_expires_at`) back into the config file. `instagram_list_accounts` shows `token_days_left` so you can monitor it. This requires `app_id` / `app_secret`.

## Available Tools

| Tool | Description |
|---|---|
| `instagram_list_accounts` | List configured IG accounts, token status, days until expiry |
| `instagram_get_account` | Your profile: followers, follows, media count, bio, website |
| `instagram_get_insights` | Account analytics: reach, profile views, follower growth, demographics |
| `instagram_list_media` | List your posts / reels / carousels (paginated) |
| `instagram_get_media` | One media object incl. carousel children |
| `instagram_get_media_insights` | Per-post metrics: reach, saves, shares, reel plays / watch time |
| `instagram_list_comments` | Comments + replies on a post (triage engagement) |
| `instagram_reply_comment` | Reply to a comment, or post a new top-level comment |
| `instagram_manage_comment` | Hide / unhide / delete a comment |
| `instagram_hashtag_search` | Find content & creators by hashtag (id + top/recent media) |
| `instagram_business_discovery` | Profile another public business/creator account by username |
| `instagram_publish` | Publish image / video / reel / story / carousel |
| `instagram_get_publishing_limit` | Posts remaining in the 24h window (max 100) |
| `instagram_refresh_token` | Extend the long-lived token (writes back to config) |
| `instagram_get` | Arbitrary read-only Graph API GET (escape hatch) |

## Publishing guide

`instagram_publish` follows Instagram's two-step model (create a media *container*, then publish it) and handles the wait for video/reel processing automatically.

**Key rule:** `image_url` / `video_url` must be **public HTTPS URLs** that Instagram's servers can download — Instagram pulls the asset from the URL, you don't upload bytes. Host the file somewhere public (your own storage/CDN, or a generated Canva export).

### Single image

```json
{
  "media_type": "IMAGE",
  "image_url": "https://cdn.example.com/post.jpg",
  "caption": "New drop \u2728 #brand #launch",
  "alt_text": "Product on a wooden table"
}
```

### Reel

```json
{
  "media_type": "REELS",
  "video_url": "https://cdn.example.com/reel.mp4",
  "caption": "Behind the scenes \ud83c\udfa5 #reels",
  "cover_url": "https://cdn.example.com/cover.jpg",
  "share_to_feed": true
}
```

### Story

```json
{ "media_type": "STORIES", "image_url": "https://cdn.example.com/story.jpg" }
```

### Carousel (2–10 items)

```json
{
  "media_type": "CAROUSEL",
  "caption": "Swipe \u2192 our top 3 looks",
  "children": [
    { "image_url": "https://cdn.example.com/1.jpg" },
    { "image_url": "https://cdn.example.com/2.jpg" },
    { "media_type": "VIDEO", "video_url": "https://cdn.example.com/3.mp4" }
  ]
}
```

### Deferred / scheduled publishing

Set `publish: false` to only build the container — the tool returns a `creation_id`. Later (e.g. from an n8n schedule), call `instagram_publish` again with that `creation_id` to publish it. Containers expire after ~24h.

> **Rate limit:** Instagram allows max **100 API-published posts per 24h** (carousels count as one). Check `instagram_get_publishing_limit` before bulk runs.

## Insights cheat-sheet

Most modern metrics require `metric_type: "total_value"`. Demographic metrics also need a `breakdown` and `timeframe`.

| Goal | Example call |
|---|---|
| Reach + profile views (last 7 days) | `metric: "reach,profile_views"`, `period: "day"` |
| Total engagement | `metric: "total_interactions,likes,comments,shares,saves"`, `metric_type: "total_value"` |
| Follower growth | `metric: "follows_and_unfollows"`, `metric_type: "total_value"` |
| Audience by country | `metric: "follower_demographics"`, `metric_type: "total_value"`, `breakdown: "country"`, `timeframe: "last_30_days"` |
| Per-reel performance | `instagram_get_media_insights` `metric: "reach,views,likes,comments,saved,shares,ig_reels_avg_watch_time"` |

Metric names evolve with each Graph API version; `instagram_get_insights` passes them straight through, and `instagram_get` covers anything else.

## Example automation workflows

**Niche discovery → outreach list**
1. `instagram_hashtag_search` `{ "hashtag": "vintagefashion", "media": "top" }`
2. For interesting posts, `instagram_business_discovery` `{ "username": "<creator>" }` to size up followers/engagement.
3. Engage: `instagram_reply_comment` on their relevant posts (authentically, not spam).

**Daily engagement triage**
1. `instagram_list_media` → newest posts.
2. `instagram_list_comments` per post → find unanswered / negative comments.
3. `instagram_reply_comment` to answer, `instagram_manage_comment` to hide spam.

**Plan → publish → measure**
1. Generate creatives (e.g. Canva), host them publicly.
2. `instagram_get_publishing_limit` → confirm headroom.
3. `instagram_publish` the reel/post/carousel.
4. A day later, `instagram_get_media_insights` to learn what worked, and feed it back into the next plan.

## Troubleshooting

**"Unsupported get request" / object id errors** — Make sure `ig_user_id` is the Instagram *professional account id* (a long number from `me/accounts`), not your @username or your Facebook Page id.

**"Application does not have permission for this action"** — A required scope is missing. Regenerate the token with all scopes from section 2.

**Hashtag search returns nothing / "limit" errors** — Instagram caps each account to ~30 unique hashtag lookups per rolling 7 days, and only returns public *business/creator* media. Re-using already-searched hashtags doesn't count against the limit.

**Business discovery returns nothing** — The target must be a *public professional* account (not private/personal), and age-gated accounts are excluded.

**Publish fails to download media** — `image_url`/`video_url` must be publicly reachable over HTTPS with no auth. Test the URL in an incognito browser.

**Video/reel publish times out** — Large videos can take a while to process. The tool returns a `creation_id` on timeout; re-run `instagram_publish` with that `creation_id` once ready, or raise `max_wait_seconds`.

**Token expired** — Run `instagram_refresh_token` (needs `app_id`/`app_secret`). If it's already fully expired, generate a fresh long-lived token via section 3.
