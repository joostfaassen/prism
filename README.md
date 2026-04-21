# Prism

**Multi-server MCP tool bridge** — expose banking, email, calendar, and custom API tools over the [Model Context Protocol](https://modelcontextprotocol.io/).

Prism lets you define multiple *servers*, each with its own bearer token and set of accounts. AI clients (Cursor, Claude Desktop, etc.) connect to a server's MCP endpoint and get access to only the tools and accounts that server is configured for.

## Architecture

```
┌─────────────┐     ┌─────────────┐
│  AI Client   │     │  AI Client   │
│  (Cursor)    │     │  (Claude)    │
└──────┬───────┘     └──────┬───────┘
       │ Bearer A           │ Bearer B
       ▼                    ▼
┌──────────────────────────────────┐
│            Prism                 │
│  /mcp/kiko      /mcp/gekkie     │
│  ┌──────────┐   ┌──────────┐    │
│  │ 9 accts  │   │ 3 accts  │    │
│  │ 17 tools │   │ 11 tools │    │
│  └──────────┘   └──────────┘    │
└────┬────┬────┬────┬─────────────┘
     │    │    │    │
     ▼    ▼    ▼    ▼
   bunq  IMAP  Cal  Cyans
```

Each server exposes the same tool *types* but scoped to its own accounts. A personal server sees all your email accounts; a shared server only sees the shared mailbox.

## Tools (24)

| Category | Tool | Description |
|---|---|---|
| **bunq** | `bunq_list_accounts` | List configured bank accounts |
| | `bunq_list_transactions` | List transactions with date/amount filters |
| | `bunq_get_transaction` | Get full transaction details |
| | `bunq_get_transaction_notes` | Get notes and attachments on a transaction |
| **IMAP** | `imap_list_accounts` | List configured email accounts |
| | `imap_list_folders` | List mailbox folders with unread counts |
| | `imap_search` | Search emails by sender, subject, date, flags |
| | `imap_get_message` | Fetch full email content |
| **Calendar** | `calendar_list_calendars` | List configured ICS calendars |
| | `calendar_list_events` | List upcoming events with date range filter |
| | `calendar_get_event` | Get event details by UID |
| **Cyans** | `cyans_get_open_topics` | Get open topics for a user |
| | `cyans_get_topic_details` | Get full topic with posts |
| | `cyans_search_topics` | Search topics by subject |
| | `cyans_add_post` | Add a post to a topic |
| **Picnic** | `picnic_list_accounts` | List configured Picnic accounts |
| | `picnic_search_products` | Search grocery products by name |
| | `picnic_get_cart` | Get the current shopping cart |
| | `picnic_add_to_cart` | Add a product to the cart |
| | `picnic_remove_from_cart` | Remove a product from the cart |
| | `picnic_list_deliveries` | List past and upcoming deliveries (optional state filter) |
| | `picnic_get_delivery` | Get full details of a single delivery |
| **Utility** | `day_name` | Get day-of-week for a date |
| | `sum` | Sum an array of numbers |

## Quick Start

### 1. Clone and install

```bash
git clone git@github.com:joostfaassen/prism.git
cd prism
composer install
npm install && npx encore dev
```

### 2. Configure

Copy the example config and edit it:

```bash
cp prism.config.yaml.example prism.config.yaml
```

```yaml
servers:
  my-server:
    label: "My Personal Server"
    bearer_token: "generate-a-random-token"
    accounts:
      my-email:
        type: imap
        label: "Personal Email"
        host: "imap.example.com"
        port: 993
        encryption: ssl
        username: "me@example.com"
        password: "app-password"
        validate_cert: true
      my-picnic:
        type: picnic
        label: "Picnic NL"
        username: "me@example.com"
        password: "your-picnic-password"
        country_code: "nl"
```

Set admin credentials:

```bash
# .env.local
APP_AUTH_USER=admin
APP_AUTH_PASSWORD=a-secure-password
```

### 3. Run with Docker

```bash
docker compose up -d
```

The app is served via Traefik. Configure the hostname in `docker-compose.yml`.

### 4. Connect your AI client

Add to your Cursor `.mcp.json`:

```json
{
  "mcpServers": {
    "prism": {
      "url": "https://your-host/mcp/my-server",
      "headers": {
        "Authorization": "Bearer generate-a-random-token"
      }
    }
  }
}
```

## Admin UI

Prism includes a built-in admin dashboard at `/admin` with:

- **Server list** — overview of all configured servers with account/tool counts
- **Configuration tab** — connection details and `.mcp.json` snippet
- **Accounts tab** — accounts grouped by type
- **Tools tab** — all available tools for the server, linking to detail pages
- **Tool detail** — input schema, parameters table, and an interactive **Try It** panel for executing tools with YAML input/output

Each tab and tool detail page has its own URL for bookmarking.

## Account Types

| Type | Config Keys | What It Connects To |
|---|---|---|
| `bunq` | `api_key`, `environment`, `monetary_account_id` | [bunq](https://www.bunq.com/) banking API |
| `imap` | `host`, `port`, `username`, `password`, `encryption` | Any IMAP mailbox |
| `calendar` | `ics_url`, `summary` | Any ICS/iCal feed (Google Calendar, etc.) |
| `cyans` | `dsn`, `username` | [Cyans](https://cyans.linkorb.com/) topic tracking API |
| `picnic` | `username`, `password`, `country_code` | [Picnic](https://picnic.app/) grocery delivery (unofficial API) |

## Tech Stack

- **PHP 8.2+** / **Symfony 7.2**
- **Tailwind CSS** via Webpack Encore
- **Docker** with Traefik reverse proxy
- MCP Protocol v2024-11-05 (JSON-RPC over HTTP with Bearer auth)

## License

MIT
