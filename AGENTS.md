# AGENTS.md — Prism Developer Guide

## What is Prism?

Prism is a **multi-server MCP (Model Context Protocol) bridge** built on Symfony 7.2. It exposes tools for banking, email, calendars, and custom APIs over MCP so that AI clients (Cursor, Claude Desktop, etc.) can interact with them.

The key design idea: you define **servers** in YAML config, each with its own bearer token and a set of **accounts**. Each account has a **type** (e.g. `bunq`, `email`, `calendar`, `cyans`). Tools are automatically scoped to servers based on which account types that server has. This means different AI clients can connect to different servers and see different sets of tools and data — a single Prism instance serves multiple tenants.

## Architecture Overview

There is **no database**. All configuration lives in `prism.config.yaml` (gitignored). The domain model is:

- **Server** (`ServerConfig`) — a named MCP endpoint with a bearer token and a map of accounts
- **Account** — a named entry in a server's `accounts` map, with a `type` and type-specific credentials
- **Tool** (`ToolInterface`) — a PHP class that implements an MCP tool; auto-registered via Symfony DI tags

The flow:

```
AI Client → POST /mcp/{serverName} (Bearer token)
  → BearerTokenAuthenticator resolves token → ServerConfig
  → McpHandler dispatches JSON-RPC method (initialize, tools/list, tools/call)
  → tools/list filters tools by server's account types
  → tools/call delegates to ToolInterface::execute()
  → Tool uses *Service + *ConfigLoader scoped to the active server's accounts
```

## Key Concepts and How They Relate

### Servers

Defined in `prism.config.yaml` under `servers:`. Each server has:
- `label` — human-readable name
- `bearer_token` — authentication secret for MCP clients
- `accounts` — map of account names to config (each must have a `type`)

Servers are loaded by `PrismConfigLoader` and stored as `ServerConfig` objects.

### Accounts

Each account is a keyed entry under a server's `accounts:` block. The `type` field determines which integration it connects to and which tools become available. Supported types: `bunq`, `email`, `calendar`, `cyans`, `slack`, `freescout`, `libredesk`, `matomo` (extend by adding your own).

Each account type has:
- An `*AccountConfig` DTO (e.g. `EmailAccountConfig`) — typed value object for credentials
- An `*ConfigLoader` (e.g. `EmailConfigLoader`) — reads raw YAML into DTOs, scoped to the current server via `ServerContext`
- An `*Service` (e.g. `EmailService`) — the actual integration logic

### Tools

Every tool implements `ToolInterface` (in `src/Mcp/Tool/`):

```php
interface ToolInterface
{
    public function getName(): string;
    public function getDescription(): string;
    public function getInputSchema(): array;    // JSON Schema
    public function getAccountType(): ?string;  // null = utility, always available
    public function execute(array $arguments): array;
}
```

Tools are auto-discovered by Symfony DI: any class implementing `ToolInterface` is tagged `app.mcp_tool` and injected into `McpHandler`.

**Visibility rule:** A tool appears on a server if and only if:
- `getAccountType()` returns `null` (utility tool — always visible), OR
- The server has at least one account whose `type` matches the tool's account type

### ServerContext

A request-scoped service that holds the active `ServerConfig` for the current request. Set by `BearerTokenAuthenticator` (for MCP requests) or by `AdminController` (for admin "Try It" execution). All config loaders use `ServerContext` to scope account access.

## Project Structure

```
src/
├── Config/
│   ├── PrismConfigLoader.php    # Loads prism.config.yaml → ServerConfig objects
│   ├── ServerConfig.php         # Server value object (name, token, accounts)
│   └── ServerContext.php        # Request-scoped active server holder
├── Controller/
│   ├── McpController.php        # POST /mcp/{serverName} — MCP JSON-RPC endpoint
│   ├── AdminController.php      # /admin — dashboard, server tabs, tool detail, Try It
│   └── HealthController.php     # / and /health — service metadata
├── Mcp/
│   ├── McpHandler.php           # JSON-RPC dispatcher (initialize, tools/list, tools/call)
│   └── Tool/
│       ├── ToolInterface.php    # Contract all tools implement
│       ├── SumTool.php          # Utility: sum numbers
│       ├── DayNameTool.php      # Utility: day of week
│       ├── BunqListAccountsTool.php
│       ├── BunqListTransactionsTool.php
│       ├── BunqGetTransactionTool.php
│       ├── BunqGetTransactionNotesTool.php
│       ├── EmailListAccountsTool.php
│       ├── EmailListFoldersTool.php
│       ├── EmailSearchTool.php
│       ├── EmailGetMessagesTool.php
│       ├── EmailSendTool.php
│       ├── CalendarListCalendarsTool.php
│       ├── CalendarListEventsTool.php
│       ├── CalendarGetEventTool.php
│       ├── CyansGetTopicsTool.php
│       ├── CyansGetTopicDetailsTool.php
│       ├── CyansSearchTopicsTool.php
│       ├── CyansAddPostTool.php
│       ├── SlackListAccountsTool.php
│       ├── SlackListChannelsTool.php
│       ├── SlackListMessagesTool.php
│       ├── SlackGetThreadRepliesTool.php
│       ├── SlackGetUnrespondedMessagesTool.php
│       ├── SlackAddReactionTool.php
│       ├── SlackPostMessageTool.php
│       ├── FreescoutListAccountsTool.php
│       ├── FreescoutListMailboxesTool.php
│       ├── FreescoutListConversationsTool.php
│       ├── FreescoutGetConversationTool.php
│       ├── FreescoutListUsersTool.php
│       ├── FreescoutCreateThreadTool.php
│       ├── LibredeskListAccountsTool.php
│       ├── LibredeskListConversationsTool.php
│       ├── LibredeskSearchConversationsTool.php
│       ├── LibredeskGetConversationTool.php
│       ├── LibredeskSendMessageTool.php
│       ├── LibredeskUpsertDraftTool.php
│       ├── LibredeskGetDraftTool.php
│       ├── LibredeskDeleteDraftTool.php
│       ├── LibredeskUpdateStatusTool.php
│       ├── LibredeskListAgentsTool.php
│       ├── LibredeskListTeamsTool.php
│       ├── MatomoListAccountsTool.php
│       ├── MatomoListSitesTool.php
│       ├── MatomoGetVisitsSummaryTool.php
│       ├── MatomoGetTopPagesTool.php
│       └── MatomoGetReportTool.php
├── Bunq/                        # bunq banking integration
│   ├── BunqAccountConfig.php
│   ├── BunqConfigLoader.php
│   └── BunqService.php
├── Email/                       # Email integration (IMAP read + SMTP send)
│   ├── EmailAccountConfig.php
│   ├── ImapConfig.php
│   ├── SmtpConfig.php
│   ├── EmailIdentity.php
│   ├── EmailConfigLoader.php
│   ├── ImapClient.php
│   ├── SmtpMailer.php
│   ├── MarkdownRenderer.php
│   ├── MessageComposer.php
│   ├── ReplyContext.php
│   ├── ComposedMessage.php
│   └── EmailService.php
├── Calendar/                    # ICS calendar integration
│   ├── CalendarConfig.php
│   ├── CalendarConfigLoader.php
│   └── CalendarService.php
├── Cyans/                       # Cyans topic tracking integration
│   ├── CyansAccountConfig.php
│   ├── CyansConfigLoader.php
│   └── CyansService.php
├── Slack/                       # Slack messaging integration (jolicode/slack-php-api)
│   ├── SlackAccountConfig.php
│   ├── SlackConfigLoader.php
│   └── SlackService.php
├── Freescout/                   # Freescout helpdesk integration (REST API)
│   ├── FreescoutAccountConfig.php
│   ├── FreescoutConfigLoader.php
│   └── FreescoutService.php
├── Libredesk/                   # Libredesk helpdesk integration (REST API)
│   ├── LibredeskAccountConfig.php
│   ├── LibredeskConfigLoader.php
│   └── LibredeskService.php
├── Matomo/                      # Matomo analytics integration (Reporting API)
│   ├── MatomoAccountConfig.php
│   ├── MatomoConfigLoader.php
│   └── MatomoService.php
└── Security/
    ├── BearerTokenAuthenticator.php  # MCP firewall: Bearer → ServerConfig
    └── EnvUserProvider.php           # Admin login from APP_AUTH_USER/PASSWORD
```

## How to Add a New Integration

Adding a new integration follows a repeatable 4-step pattern. Each existing integration (bunq, email, calendar, cyans, slack) demonstrates this pattern.

### Step 1: Create the Account Config DTO

Create `src/Slack/SlackAccountConfig.php`:

```php
namespace App\Slack;

class SlackAccountConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $botToken,
        public readonly string $defaultChannel,
    ) {
    }
}
```

### Step 2: Create the Config Loader

Create `src/Slack/SlackConfigLoader.php`:

```php
namespace App\Slack;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class SlackConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /** @return array<string, SlackAccountConfig> */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('slack', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $accounts[$key] = new SlackAccountConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                botToken: $cfg['bot_token'] ?? '',
                defaultChannel: $cfg['default_channel'] ?? 'general',
            );
        }

        return $accounts;
    }

    public function getAccount(string $key): SlackAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Slack account: "%s". Available: %s',
                $key,
                implode(', ', array_keys($accounts)),
            ));
        }

        return $accounts[$key];
    }
}
```

### Step 3: Create the Service

Create `src/Slack/SlackService.php` — the actual API integration. Inject `HttpClientInterface` (for REST APIs), the config loader, and any other dependencies.

### Step 4: Create Tool Classes

Create one or more classes in `src/Mcp/Tool/` that implement `ToolInterface`:

```php
namespace App\Mcp\Tool;

use App\Slack\SlackService;

class SlackSendMessageTool implements ToolInterface
{
    public function __construct(
        private readonly SlackService $slackService,
    ) {
    }

    public function getName(): string
    {
        return 'slack_send_message';
    }

    public function getDescription(): string
    {
        return 'Send a message to a Slack channel';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Slack account key',
                ],
                'channel' => [
                    'type' => 'string',
                    'description' => 'Channel name or ID',
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'Message text to send',
                ],
            ],
            'required' => ['account', 'message'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'slack';
    }

    public function execute(array $arguments): array
    {
        try {
            $result = $this->slackService->sendMessage(
                accountKey: $arguments['account'],
                channel: $arguments['channel'] ?? null,
                message: $arguments['message'],
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
```

**That's it.** No registration code needed — Symfony auto-discovers the tool via the `ToolInterface` tag.

### Step 5: Add Account Config in YAML

Add accounts to the appropriate server(s) in `prism.config.yaml`:

```yaml
servers:
  my-server:
    label: "My Server"
    bearer_token: "my-secret-token"
    accounts:
      work-slack:
        type: slack
        label: "Work Slack"
        bot_token: "xoxb-..."
        default_channel: "general"
```

## Adding a Utility Tool (No Account Required)

For tools that don't require external service credentials (date math, text processing, calculations), return `null` from `getAccountType()`. These tools are available on every server.

See `SumTool` and `DayNameTool` for examples.

## Adding a New Server

Add a new entry under `servers:` in `prism.config.yaml`:

```yaml
servers:
  new-server:
    label: "New Server"
    bearer_token: "generate-a-unique-token"
    accounts:
      # Add any accounts this server should have access to
      my-email:
        type: email
        imap:
          host: "imap.example.com"
          # ... IMAP credentials
        smtp:
          host: "smtp.example.com"
          # ... SMTP credentials (optional, required for email_send)
        identity:
          email: "user@example.com"
```

The server instantly gets its own MCP endpoint at `/mcp/new-server` and appears in the admin dashboard.

## Integration Patterns for AI-Assisted Development

Prism's architecture is specifically designed for rapid tool development with AI assistance. Here are the three main patterns:

### Pattern A: Internal Apps (Direct API Access)

For internal services where you control the API and can connect directly:

1. Tell the AI: "Add a new integration for [service name]. The API is at [base URL], uses [auth method], and I need tools for [list operations]."
2. Provide the API documentation or example curl commands.
3. The AI creates the 4-file integration (AccountConfig, ConfigLoader, Service, Tool classes) following the established pattern.

**Example prompt:** "Add a Jira integration. The API uses Basic Auth with an API token. I need tools to list issues, get issue details, and add comments. Here's the API docs: [paste or link]."

The AI can model the entire integration after existing ones like `Cyans/` (HTTP API with auth) — provide the Cyans files as reference.

### Pattern B: Public Apps Without MCP (REST/GraphQL APIs)

For third-party services that have REST or GraphQL APIs but don't offer their own MCP server:

1. Tell the AI: "Create tools to wrap [service]'s API. Here are the endpoints I need: [list]."
2. If the API is well-known (GitHub, Stripe, Twilio, etc.), the AI likely knows the API already.
3. For complex APIs, install the official PHP SDK via Composer and have the AI wrap it (like `bunq/sdk_php` is used for the bunq integration).

**Example prompt:** "Add a GitHub integration with tools: list repos, list issues, create issue, add comment. Use the GitHub REST API with a personal access token."

**Key considerations:**
- For SDKs: `composer require vendor/package`, create a Service that wraps the SDK, then create Tool classes that call the Service
- For raw REST APIs: use Symfony's `HttpClientInterface` (already available via autowiring)
- Always scope by account — the same tool should work with different credentials on different servers

### Pattern C: Proxying Access-Controlled MCP Servers

For services that already have their own MCP server but where you want centralized access control, credential management, or multi-tenant routing:

1. The tool's Service class becomes an **MCP client** — it connects to the upstream MCP server using Symfony's HTTP client
2. Tool classes proxy `tools/call` to the upstream, passing through arguments
3. Prism adds its own auth layer (bearer tokens) and scoping (which upstream servers each Prism server can reach)

**Example prompt:** "Add a proxy integration for an upstream MCP server. The upstream is at [URL] and uses Bearer auth. I want to proxy these tools: [list]. The upstream credentials should be stored in account config."

**Example account config:**

```yaml
upstream-service:
  type: mcp_proxy
  label: "Upstream Service"
  endpoint: "https://upstream.example.com/mcp"
  bearer_token: "upstream-token"
  allowed_tools:
    - "tool_a"
    - "tool_b"
```

This pattern lets you:
- Aggregate multiple MCP servers behind one endpoint
- Apply per-server access control over which upstream tools are exposed
- Manage upstream credentials centrally instead of distributing them to each AI client

## Development Environment

- **Runtime:** PHP 8.4, Apache, Docker container with Traefik reverse proxy
- **URL:** Your Traefik hostname (e.g. `https://prism.localhost` — see `docker-compose.yml.example` labels)
- **Hot reload:** The repo is bind-mounted to `/app` in the container — code changes are instant
- **No local dev server needed** — use the hostname you configure in Traefik (see `docker-compose.yml.example`)
- **Admin UI:** `{your-base-url}/admin` (credentials from `APP_AUTH_USER`/`APP_AUTH_PASSWORD` in `.env.local`)
- **Frontend build:** `npx encore dev` (or `npx encore production` for the Docker image build)

### Running the App

```bash
docker compose up -d
```

### Key Config Files

| File | Purpose | Committed? |
|---|---|---|
| `prism.config.yaml` | Server/account definitions (secrets) | No (gitignored) |
| `prism.config.yaml.example` | Scrubbed template for new setups | Yes |
| `.env.local` | Admin credentials, APP_SECRET | No (gitignored) |
| `docker-compose.yml` | Container + Traefik config | No (gitignored) |
| `docker-compose.yml.example` | Generic Traefik labels template | Yes |
| `config/services.yaml` | Symfony service wiring | Yes |
| `config/packages/security.yaml` | Firewall and access control | Yes |

### No Database

There are no Doctrine entities, migrations, or database connections. All state is in `prism.config.yaml` and the external services themselves.

### No Tests

There is currently no test suite. When adding tests, use PHPUnit with `tests/` directory (PSR-4 namespace `App\Tests\` is already configured in `composer.json`).

## Conventions

- **Tool names:** lowercase snake_case, prefixed with the account type (e.g. `bunq_list_accounts`, `email_search`). Utility tools use a descriptive name without prefix.
- **Account type strings:** lowercase, match the `type` field in YAML config. Must be consistent between config, `*ConfigLoader`, and `ToolInterface::getAccountType()`.
- **Tool execute() return format:** Always return `['content' => [['type' => 'text', 'text' => '...']]]`. Add `'isError' => true` for error responses. JSON-encode structured data in the text field.
- **Error handling:** Catch exceptions in `execute()` and return MCP error format — don't let exceptions bubble up unhandled.
- **Input validation:** Validate required arguments at the start of `execute()` and return clear error messages.
- **Config DTOs:** Use readonly constructor-promoted properties. Keep them simple value objects.
- **Services:** Inject `HttpClientInterface` for HTTP APIs. Inject `*ConfigLoader` for account access. Always scope to the active server via `ServerContext`.

## MCP Protocol Details

- Protocol version: `2024-11-05`
- Transport: HTTP POST with JSON-RPC 2.0
- Auth: `Authorization: Bearer <token>` header
- Supported methods: `initialize`, `tools/list`, `tools/call`, `notifications/initialized`, `ping`
- Batch requests: Send a JSON array of requests, receive a JSON array of responses
- The `tools/call` response shape matches MCP spec: `{ content: [{ type, text }], isError? }`

## Quick Reference: Adding Things

| I want to... | What to create | Where |
|---|---|---|
| Add a new server | YAML entry | `prism.config.yaml` |
| Add an account to a server | YAML entry under server's `accounts:` | `prism.config.yaml` |
| Add a new account type | AccountConfig + ConfigLoader + Service | `src/{TypeName}/` |
| Add a tool for an existing type | Tool class implementing `ToolInterface` | `src/Mcp/Tool/` |
| Add a utility tool | Tool class with `getAccountType() → null` | `src/Mcp/Tool/` |
| Add a new integration end-to-end | All of the above | See "How to Add a New Integration" |
