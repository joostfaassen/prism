# Slack Integration

Prism's Slack integration lets AI clients list channels, read messages, detect unresponded messages, post replies, and add emoji reactions — all as your own Slack user.

## 1. Create a Slack App

1. Go to <https://api.slack.com/apps> and click **Create New App** → **From scratch**.
2. Give it a name (e.g. "Prism") and pick the workspace you want to connect.

## 2. Add User Token Scopes

Under **OAuth & Permissions** → **User Token Scopes**, add:

| Scope | Purpose |
|---|---|
| `channels:read` | List public channels |
| `groups:read` | List private channels |
| `im:read` | List DMs |
| `mpim:read` | List group DMs |
| `channels:history` | Read public channel messages |
| `groups:history` | Read private channel messages |
| `im:history` | Read DM messages |
| `mpim:history` | Read group DM messages |
| `users:read` | Resolve user IDs to names |
| `chat:write` | Post messages as yourself |
| `reactions:write` | Add emoji reactions |

> **Why User Token Scopes?** Using a User OAuth Token (`xoxp-...`) means messages and reactions appear as *you*, not as a bot. If you only need read access, you can skip `chat:write` and `reactions:write`.

## 3. Install and Get Your Token

1. Click **Install to Workspace** (or **Reinstall** if updating scopes).
2. Authorize the app — you're granting it permission to act as you.
3. Copy the **User OAuth Token** (starts with `xoxp-`).

## 4. Configure in `prism.config.yaml`

Add a `slack` account to any server:

```yaml
servers:
  my-server:
    label: "My Server"
    bearer_token: "your-prism-bearer-token"
    accounts:
      work-slack:
        type: slack
        label: "Work Slack"
        token: "xoxp-your-user-oauth-token"
```

That's it — the server will now expose all Slack tools.

### Multiple Workspaces

Add one account per workspace. Each needs its own Slack App and token:

```yaml
accounts:
  work-slack:
    type: slack
    label: "Work"
    token: "xoxp-work-token"
  oss-slack:
    type: slack
    label: "Open Source"
    token: "xoxp-oss-token"
```

Every tool accepts an `account` parameter so the AI client can target the right workspace.

## Available Tools

| Tool | Description |
|---|---|
| `slack_list_accounts` | List configured Slack workspaces |
| `slack_list_channels` | List channels, DMs, and group conversations (filterable by type) |
| `slack_list_messages` | Read message history in a channel with pagination |
| `slack_get_thread_replies` | Get all replies in a message thread |
| `slack_get_unresponded_messages` | Find messages from others you haven't replied to |
| `slack_get_messages_with_threads` | Read messages and expand top threads in one call |
| `slack_bulk_get_threads` | Fetch multiple threads in one request with partial success details |
| `slack_resolve_ids` | Resolve user/channel IDs in bulk to readable metadata |
| `slack_add_reaction` | Add an emoji reaction (e.g. `thumbsup`, `eyes`) |
| `slack_post_message` | Post a message or thread reply |

## Typical Workflow

1. `slack_list_accounts` → find the account key
2. `slack_list_channels` → find the channel ID
3. `slack_get_messages_with_threads` → read recent messages with thread context in one request
4. `slack_get_unresponded_messages` → see what needs attention
5. `slack_resolve_ids` → resolve user/channel IDs for cleaner outputs when needed
6. `slack_add_reaction` / `slack_post_message` → respond

## Performance Notes

- Prism caches frequently repeated Slack reads:
  - account auth info and user id
  - channel list snapshots
  - workspace directory snapshots
  - short-lived message/thread pages
- Cache entries for channel/thread reads are invalidated after `slack_post_message` and `slack_add_reaction` by bumping an internal channel version key.
- For agent usage, prefer:
  - `slack_get_messages_with_threads` over separate `slack_list_messages` + repeated `slack_get_thread_replies`
  - `slack_bulk_get_threads` when expanding many threads
  - `slack_resolve_ids` when resolving many IDs in one request

## Troubleshooting

**"missing_scope" error** — You need to add the required scope in your Slack App's OAuth settings and reinstall the app.

**Can't see private channels or DMs** — The user token can only access conversations you're a member of. Join the channel first in Slack.

**"channel_not_found"** — Make sure you're using the channel ID (e.g. `C01ABC123`), not the channel name. Get IDs from `slack_list_channels`.

**"not_in_channel"** — For public channels, you may need to join them first before posting. The token gives access to list them, but posting requires membership.
