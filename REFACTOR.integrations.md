# REFACTOR — Self-contained integrations under `src/Integrations/`

Status: **planned, not started**
Executor: an AI coding agent, working phase by phase, committing per phase.

**North star:** after (or as a deliberate follow-on to) the mechanical module move,
integrations become reusable **Action packs** (Nebula action methodology), importable
into Prism from private / work-internal / public Composer packages, and exposable over
**MCP** (primary) plus optionally **REST** and **CLI**. See §1.1 and §11.

---

## 1. Why

`src/` is currently flat: 28 integrations (each 3–4 classes), ~197 of their MCP tool
classes in one big `src/Mcp/Tool/` directory, plus controllers, commands, entities and
repositories all mixed at the same level. Adding integration #29 touches four unrelated
directories.

Near-term target (Phases 0–8): every integration becomes **one self-contained module**
under `src/Integrations/{Name}/` holding its account config, config loader, service, MCP
tools, and (where applicable) its controllers, commands, entities and repositories.
A new `IntegrationInterface` + `IntegrationRegistry` makes integrations enumerable, and
a new admin page `/admin/integrations` lists every integration with its account count
and tools.

Strategic target (Phase 9 / Action methodology — see §11): those modules evolve into
(or are replaced by) **ActionPacks** built on Nebula's `linkorb/action-component` (+
Symfony host patterns from `linkorb/action-bundle`). Prism becomes a **multi-tenant
Action host**: it *imports* packs from many repos and *exposes* them over MCP (and
optionally REST/CLI), with Prism's server/account scoping unchanged.

**Externally nothing changes during Phases 0–8** (except the new admin page): MCP
endpoints, tool names, account `type:` strings, YAML config format, routes, and the
database schema all stay identical. Phase 9 may add transports and packaging; tool
names / account types still must not break unless explicitly planned.

### 1.1 Action methodology (north star)

Reference implementation lives in the Nebula monorepo (`linkorb/nebula`):

| Package | Path in Nebula | Role |
|---|---|---|
| `linkorb/action-component` | `packages/action-component/` | Framework-agnostic FaaS-like **Action** objects: definition, validation, execution, `ActionManager` registry. Designed for multiple transports (CLI, HTTP, MCP, message bus, …). |
| `linkorb/action-bundle` | `packages/action-bundle/` | Symfony host: Web UI to list/run actions, `action:list` / `action:view` / per-action CLI commands, HTTP controller, and an **MCP controller** that builds tools from `ActionManager`. |

Core ideas to reuse (do not reinvent in Prism):

- **Action** — named operation with input/output JSON Schema, executed via an executor;
  result is an `ActionResult` (not MCP-specific).
- **ActionPack** — group of related actions (PHP attributes `#[ActionPack]` /
  `#[Action]` on methods, or YAML via `ConfigFactory`). Example in Nebula:
  `WeatherActionPack` tagged `action.pack`.
- **ActionManager** — registry of all actions; transports only adapt list/execute.
- **Hosts** — transport adapters (`ConsoleHost`, buffered/callback hosts, etc.) so the
  same action runs from CLI, HTTP, or MCP without duplicating business logic.

Desired Prism shape:

```
private / work-internal / public Composer packages
  (each may ship one or more ActionPacks + optional account helpers)
        │
        ▼  composer require
┌───────────────────┐
│  Prism (host)     │  servers + accounts + bearer tokens (unchanged)
│  ActionManager    │  ◄── packs from many packages
│  transports:      │
│   - MCP  (now)    │  /mcp/{serverName}
│   - REST (maybe)  │  thin Action HTTP API
│   - CLI  (maybe)  │  action:* style commands
└───────────────────┘
```

Why this matters for the Phases 0–8 layout:

1. **`src/Integrations/{Name}/` should map 1:1 to a future ActionPack** (or a small
   set of packs). Keep service/domain logic free of MCP response shaping where
   practical so Phase 9 can wrap it as Actions without rewriting integrations.
2. **Tool classes stay thin adapters** for now (`ToolInterface` → service). In Phase 9
   they become (or are generated from) Actions; MCP is just one transport.
3. **Reusable across repos** — personal Prism, work Nebula/HQ, and public OSS packs
   should share the same Action contract so Prism can `composer require` them rather
   than copy-paste `*Tool.php` files.
4. **Do not implement Phase 9 inside Phases 0–8** unless the user explicitly expands
   scope. Phases 0–8 remain a zero-behavior-change move + registry/UI.

## 2. Hard invariants — MUST NOT change

The executor must treat these as failure conditions. If a step would change any of
them, stop and report instead.

1. **Tool names** — every `ToolInterface::getName()` return value stays identical.
2. **Account type strings** — `getAccountType()` values and the `type:` values in
   prism config files stay identical.
3. **Routes** — every route path *and* route name stays identical (new
   `admin_integrations*` routes are the only additions).
4. **Class basenames** — only namespaces change. `CalendarConfig` stays
   `CalendarConfig` (do **not** "fix" it to `CalendarAccountConfig`), `Ga4` stays
   `Ga4`, `OpenAi` stays `OpenAi`, etc.
5. **Database schema** — table/column names derive from class basenames (underscore
   naming strategy), which don't change. `doctrine:schema:update --dump-sql` output
   must be identical before and after.
6. **Template file paths** — Twig templates do **not** move in this refactor.
7. **prism config format** — no changes to `prism.config.yaml` /
   `prism.{serverName}.yaml` structure or to `prism.config.yaml.example`.
8. **composer.json** — no changes needed in Phases 0–8 (PSR-4 `App\` → `src/`
   covers everything). Phase 9 may add Action package dependencies (§11).
9. Tool ordering inside MCP `tools/list` MAY change (registration order follows
   directory scan order). That is acceptable; all verification diffs use sorted output.
10. **ActionPack-friendly modules** — when moving code, do not introduce new
    MCP-only logic inside services; keep `*Tool` classes as the MCP adapter layer
    so Phase 9 can swap adapters for Actions without rewriting domain code.

## 3. Current state inventory

### 3.1 Integrations (account-type based) — all 28 move

Tool files live in `src/Mcp/Tool/` and are matched by class-name prefix. The file
count column is the number of `{Prefix}*Tool.php` files to move (verify with `ls`
before moving; counts include abstract base classes).

| Type string | Source dir | Tool prefix | Tool files | Extras (see phase notes) |
|---|---|---|---|---|
| `alertmanager` | `src/Alertmanager/` | `Alertmanager` | 7 | — |
| `apify` | `src/Apify/` | subdir `src/Mcp/Tool/Apify/` | 18 | tools already in a subdir incl. `AbstractApifyActorTool`; `ApifyAdminController` |
| `atlas` | `src/Atlas/` | `Atlas` | 8 | — |
| `browserless` | `src/Browserless/` | `Browserless` | 5 | — |
| `bunq` | `src/Bunq/` | `Bunq` | 4 | services.yaml entry (`$projectDir`) |
| `calendar` | `src/Calendar/` | `Calendar` | 3 | config DTO is named `CalendarConfig` — keep |
| `canva` | `src/Canva/` | `Canva` | 4 | `CanvaTokenStore` (services.yaml `$configPath`); `CanvaAdminController` (OAuth) |
| `cyans` | `src/Cyans/` | `Cyans` | 4 | — (pilot integration) |
| `email` | `src/Email/` | `Email` | 11 | 14 src files; `MessageCache` (services.yaml `@cache.email`); `EmailWarmImapCacheCommand` |
| `freescout` | `src/Freescout/` | `Freescout` | 6 | — |
| `ga4` | `src/Ga4/` | `Ga4` | 3 | services.yaml entry (`$projectDir`) |
| `github` | `src/GitHub/` | `GitHub` | 3 | — |
| `habits` | `src/Habits/` | `Habits` | 15 | `Entity/` subdir (6 entities, doctrine.yaml mapping); `HabitsAdminController`, `HabitsApiController`; `HabitsProcessCheckInsCommand` |
| `igdb` | `src/Igdb/` | `Igdb` | 3 | services.yaml entry (`IgdbService` `$projectDir` for OAuth token cache) |
| `instagram` | `src/Instagram/` | `Instagram` | 15 | `InstagramTokenStore` (services.yaml `$configPath`) |
| `libredesk` | `src/Libredesk/` | `Libredesk` | 13 | — |
| `loki` | `src/Loki/` | `Loki` | 5 | — |
| `matomo` | `src/Matomo/` | `Matomo` | 5 | — |
| `n8n` | `src/N8n/` | `N8n` | 5 | — |
| `openai` | `src/OpenAi/` | `OpenAi` | 3 | — |
| `picnic` | `src/Picnic/` | `Picnic` | 7 | services.yaml entry (`$projectDir`) |
| `prometheus` | `src/Prometheus/` | `Prometheus` | 9 | — |
| `sendgrid` | `src/SendGrid/` | `SendGrid` | 7 | — |
| `slack` | `src/Slack/` | `Slack` | 11 | `SlackCache` (services.yaml `@cache.slack`) |
| `tmdb` | `src/Tmdb/` | `Tmdb` | 11 | — |
| `tracking` | `src/Tracking/` | `Tracking` | 5 | 4 entities in `src/Entity/`, 4 repositories in `src/Repository/`; `TrackingAdminController`, `TrackingIngestController` |
| `transip` | `src/Transip/` | `Transip` | 8 | services.yaml entry (`$projectDir`) |
| `twilio` | `src/Twilio/` | `Twilio` | 5 | `TranscriptionStore` (services.yaml `$projectDir`); `TwilioTranscribeCallsCommand` (uses core `WhisperService`) |

### 3.2 Stays core (does NOT move)

| What | Why |
|---|---|
| `src/Config/` (PrismConfigLoader, ServerConfig, ServerContext) | core config model |
| `src/Security/` | core auth |
| `src/Mcp/McpHandler.php`, `src/Mcp/Tool/ToolInterface.php` | core MCP protocol layer |
| Utility tools: `SumTool`, `DayNameTool` (in `src/Mcp/Tool/`) | no account type |
| Document feature: `Document*Tool` (4 tools), `src/Entity/Document*`, `src/Repository/Document*Repository`, `DocumentController`, `DocumentTypeController` | utility feature, not account-typed |
| `src/AgentNotify/` | core per-server notify subsystem (used by AdminController + Habits) |
| `src/Whisper/` | shared transcription subsystem (own `whisper:` config key, provider tag), consumed by Twilio's command |
| `src/Controller/` Admin/Mcp/Health/Document controllers | core UI/API |
| `src/Kernel.php` | — |

After all phases, `src/Mcp/Tool/` contains exactly **7 files**: `ToolInterface.php`,
`SumTool.php`, `DayNameTool.php`, `DocumentGetTool.php`, `DocumentListTool.php`,
`DocumentNoteGetTool.php`, `DocumentTypeListTool.php`. `src/Command/` contains only
the new `McpToolsListCommand.php` (the three existing commands move with their
integrations).

## 4. Target layout

```
src/
├── Integrations/
│   ├── IntegrationInterface.php
│   ├── IntegrationRegistry.php
│   ├── Bunq/
│   │   ├── BunqIntegration.php          # NEW: metadata (type, label, description)
│   │   ├── BunqAccountConfig.php
│   │   ├── BunqConfigLoader.php
│   │   ├── BunqService.php
│   │   └── Tool/
│   │       ├── BunqListAccountsTool.php
│   │       └── ...
│   ├── Habits/
│   │   ├── HabitsIntegration.php
│   │   ├── Habits{AccountConfig,ConfigLoader,Service}.php
│   │   ├── Command/HabitsProcessCheckInsCommand.php
│   │   ├── Controller/{HabitsAdminController,HabitsApiController}.php
│   │   ├── Entity/…                     # 6 habit entities
│   │   └── Tool/…
│   ├── Tracking/
│   │   ├── TrackingIntegration.php
│   │   ├── …service/loader/helpers…
│   │   ├── Controller/{TrackingAdminController,TrackingIngestController}.php
│   │   ├── Entity/{GpsSample,TrackingDevice,TrackingZone,ZoneEvent}.php
│   │   ├── Repository/{…4 repositories…}.php
│   │   └── Tool/…
│   └── …one dir per integration…
├── Mcp/            # handler + ToolInterface + utility/document tools only
├── Config/  Security/  AgentNotify/  Whisper/  Controller/  Command/
├── Entity/         # Document, DocumentNote, DocumentType only
└── Repository/     # Document*Repository only
```

Naming rules:

- Directory names are the **existing** dir names moved as-is (`Ga4`, `OpenAi`, `N8n`,
  `GitHub`, `SendGrid`, `Transip`, …).
- Namespace = `App\Integrations\{Name}` (dir name verbatim), tools in
  `App\Integrations\{Name}\Tool`, then `\Controller`, `\Command`, `\Entity`,
  `\Repository` subnamespaces where applicable.
- `Tool/` singular (matches existing convention).

## 5. New core code (created in Phase 0/1)

### 5.1 `src/Command/McpToolsListCommand.php` — verification harness (Phase 0)

A tiny console command used to diff the tool inventory before/after every phase:

```php
<?php

namespace App\Command;

use App\Mcp\McpHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mcp:tools', description: 'List all registered MCP tools (name + account type), sorted')]
class McpToolsListCommand extends Command
{
    public function __construct(
        private readonly McpHandler $mcpHandler,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lines = [];
        foreach ($this->mcpHandler->getTools() as $tool) {
            $lines[] = $tool->getName() . "\t" . ($tool->getAccountType() ?? '-');
        }
        sort($lines);
        foreach ($lines as $line) {
            $output->writeln($line);
        }

        return Command::SUCCESS;
    }
}
```

### 5.2 `src/Integrations/IntegrationInterface.php` (Phase 1)

```php
<?php

namespace App\Integrations;

interface IntegrationInterface
{
    /**
     * Account type string. Must match the `type:` value in prism config files
     * and ToolInterface::getAccountType() of this integration's tools.
     */
    public function getType(): string;

    /** Human-readable name, e.g. "bunq". */
    public function getLabel(): string;

    /** One-line description shown on the integrations admin page. */
    public function getDescription(): string;
}
```

### 5.3 `src/Integrations/IntegrationRegistry.php` (Phase 1)

```php
<?php

namespace App\Integrations;

class IntegrationRegistry
{
    /** @var array<string, IntegrationInterface> keyed by type */
    private array $integrations = [];

    /**
     * @param iterable<IntegrationInterface> $integrations
     */
    public function __construct(iterable $integrations)
    {
        foreach ($integrations as $integration) {
            $this->integrations[$integration->getType()] = $integration;
        }
        ksort($this->integrations);
    }

    /** @return array<string, IntegrationInterface> sorted by type */
    public function all(): array
    {
        return $this->integrations;
    }

    public function get(string $type): ?IntegrationInterface
    {
        return $this->integrations[$type] ?? null;
    }

    public function has(string $type): bool
    {
        return isset($this->integrations[$type]);
    }
}
```

### 5.4 Wiring (Phase 1)

`config/services.yaml` — add:

```yaml
    App\Integrations\IntegrationRegistry:
        arguments:
            $integrations: !tagged_iterator app.integration
```

and inside the existing `_instanceof:` block:

```yaml
        App\Integrations\IntegrationInterface:
            tags: ['app.integration']
```

`config/routes.yaml` — add (so integration controllers keep working after they move):

```yaml
integration_controllers:
    resource:
        path: ../src/Integrations/
        namespace: App\Integrations
    type: attribute
```

### 5.5 Per-integration metadata class (created during each migration)

Example — `src/Integrations/Cyans/CyansIntegration.php`:

```php
<?php

namespace App\Integrations\Cyans;

use App\Integrations\IntegrationInterface;

class CyansIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'cyans';
    }

    public function getLabel(): string
    {
        return 'Cyans';
    }

    public function getDescription(): string
    {
        return 'Cyans topic tracking — list, search and read topics; add posts.';
    }
}
```

Labels and descriptions for all 28 integrations are in §9. Use them verbatim.

## 6. Execution protocol

### 6.1 Environment

- Run everything from the repo root. The app runs in Docker (`docker compose up -d`,
  service name `app`; repo bind-mounted at `/app`, code changes are instant).
- Console commands: `docker compose exec app php bin/console <cmd>`.
- `composer dump-autoload` can run on the host (composer is installed) — only needed
  if autoload behaves oddly; PSR-4 dev autoloading picks up moves automatically.

### 6.2 Preconditions (check before Phase 0)

1. `git status` must be clean (no staged/unstaged/untracked changes under `src/` or
   `config/`). If not clean: **stop and ask the user** — pending work must be
   committed first, it is not part of this refactor.
2. `docker compose up -d` and `docker compose exec app php bin/console about` works.

### 6.3 Verification loop — run after EVERY phase (and every batch item)

```bash
docker compose exec app php bin/console cache:clear
docker compose exec app php bin/console lint:container
docker compose exec app php bin/console mcp:tools > /tmp/tools-now.txt
diff var/refactor/tools-baseline.txt /tmp/tools-now.txt          # MUST be empty
docker compose exec app php bin/console debug:router > /tmp/routes-now.txt
diff var/refactor/routes-baseline.txt /tmp/routes-now.txt        # MUST be empty *
```

\* exception: Phase 3 adds the two `admin_integrations*` routes — regenerate the
routes baseline once at the end of Phase 3.

For phases touching Doctrine (7) additionally:

```bash
docker compose exec app php bin/console doctrine:mapping:info    # expect 13 entities
docker compose exec app php bin/console doctrine:schema:update --dump-sql > /tmp/schema-now.txt 2>&1 || true
diff var/refactor/schema-baseline.txt /tmp/schema-now.txt        # MUST be empty
```

For phases touching templates/controllers (3 only):

```bash
docker compose exec app php bin/console lint:twig templates/
```

### 6.4 Commit protocol

- One commit per phase; for batch phases (4–7) one commit **per integration**.
- Conventional Commits, e.g.:
  - `chore(dev): add mcp:tools inventory command`
  - `refactor(integrations): scaffold integration module layer`
  - `refactor(cyans): move cyans integration to src/Integrations/Cyans`
  - `feat(admin): add /admin/integrations overview`
  - `docs: update AGENTS.md for src/Integrations layout`
- Never use `--no-verify`. Never commit `prism.config.yaml`, `prism.*.yaml`,
  `docker-compose.yml`, `.env.local`.

### 6.5 Failure protocol

If any verification diff is non-empty or the container fails to lint: stop, do not
improvise workarounds. Revert the uncommitted phase (`git checkout -- . && git clean -fd src/Integrations` —
careful: only if the phase is fully uncommitted), re-read the phase instructions, retry
once. If it fails again, report to the user with the exact diff/error.

---

## 7. Phases

### Phase 0 — Baseline + harness

1. Create `src/Command/McpToolsListCommand.php` (code in §5.1).
2. Capture baselines (note: `var/` is gitignored):

```bash
mkdir -p var/refactor
docker compose exec app php bin/console cache:clear
docker compose exec app php bin/console mcp:tools > var/refactor/tools-baseline.txt
docker compose exec app php bin/console debug:router > var/refactor/routes-baseline.txt
docker compose exec app php bin/console doctrine:schema:update --dump-sql > var/refactor/schema-baseline.txt 2>&1 || true
wc -l var/refactor/tools-baseline.txt   # expect 194 tools
```

3. Sanity: `var/refactor/tools-baseline.txt` must contain 194 lines and include e.g.
   `bunq_list_accounts`, `email_search`, `habits_scoreboard`.
4. Commit: `chore(dev): add mcp:tools inventory command`.

### Phase 1 — Scaffolding

1. Create `src/Integrations/IntegrationInterface.php` and
   `src/Integrations/IntegrationRegistry.php` (§5.2, §5.3).
2. Apply the `services.yaml` and `routes.yaml` additions (§5.4).
3. Run the verification loop (registry is empty — that's fine; `lint:container` and
   both diffs must pass).
4. Commit: `refactor(integrations): scaffold integration module layer`.

### Phase 2 — Pilot migration: Cyans (THE RECIPE)

This is the canonical recipe. Phases 4–7 repeat it with the per-integration notes.

1. **Move the integration dir:**

```bash
git mv src/Cyans src/Integrations/Cyans
mkdir src/Integrations/Cyans/Tool
git mv src/Mcp/Tool/Cyans*Tool.php src/Integrations/Cyans/Tool/
```

   Confirm the moved tool file count matches the inventory table (§3.1): 4.

2. **Rewrite namespaces** (exact, case-sensitive string edits):
   - In `src/Integrations/Cyans/*.php`:
     `namespace App\Cyans;` → `namespace App\Integrations\Cyans;`
   - In `src/Integrations/Cyans/Tool/*.php`:
     `namespace App\Mcp\Tool;` → `namespace App\Integrations\Cyans\Tool;`
   - **GOTCHA:** moved tool classes previously lived in the same namespace as
     `ToolInterface`, so they have **no** `use` statement for it. Add
     `use App\Mcp\Tool\ToolInterface;` to every moved tool file.

3. **Update references project-wide:** replace the exact string `App\Cyans\` with
   `App\Integrations\Cyans\` in `src/` and `config/`. (This fixes the
   `use App\Cyans\CyansService;` lines inside the moved tools too.)

4. **Verify nothing is left behind:**

```bash
rg -n 'App\\Cyans\\' src config templates    # MUST output nothing
ls src/Cyans 2>/dev/null                     # MUST not exist
```

5. **Add the metadata class** `src/Integrations/Cyans/CyansIntegration.php` (§5.5,
   copy from §9).

6. Run the verification loop (§6.3). The tools diff must be empty — same 194 tools,
   same names, same account types.

7. Commit: `refactor(cyans): move cyans integration to src/Integrations/Cyans`.

### Phase 3 — Admin UI: `/admin/integrations`

New core controller `src/Controller/IntegrationsController.php` (this is core UI, it
stays in `src/Controller/`):

- `#[Route('/admin/integrations', name: 'admin_integrations', methods: ['GET'])]` —
  list page.
- `#[Route('/admin/integrations/{type}', name: 'admin_integration_detail', methods: ['GET'])]`
  — detail page (404 via `createNotFoundException` for unknown types).
- Constructor deps: `IntegrationRegistry`, `McpHandler`, `PrismConfigLoader`.
- `^/admin` is already behind the admin firewall — no security.yaml changes.

Data to assemble per integration (list page):

- `type`, `label`, `description` (from the registry);
- `toolCount`: count of `McpHandler::getTools()` where `getAccountType() === type`;
- `accountCount`: sum over `PrismConfigLoader::getServers()` of
  `count($server->getAccountsByType($type))`;
- `serverCount`: number of servers with ≥1 account of this type.

List page additionally shows:

- **Unregistered types warning** (amber callout): account types that occur in any
  tool's `getAccountType()` or any server account's `type:` but are NOT in the
  registry. During phases 4–7 this section shows the remaining migration work; after
  Phase 8 it must be empty and acts as a permanent regression guard for future
  integrations.
- **Utilities card**: tools with `getAccountType() === null` (name + description),
  labelled "Utility tools — available on every server".

Detail page (`/admin/integrations/{type}`): label, description, type string, the tool
list (name, description), and accounts grouped per server (server name/label + account
key + account label). No secrets — never print tokens/passwords from account config;
only `label` and the account key.

Templates: `templates/admin/integrations/list.html.twig` and `detail.html.twig`,
extending `base.html.twig`. Reuse the visual language of
`templates/admin/dashboard.html.twig` (dark slate/cyan cards, top nav bar). Add an
"Integrations" link to the dashboard top nav (next to "Sign Out") pointing to
`admin_integrations`, and a "Back to dashboard" link on the integrations pages.

Verify: verification loop + `lint:twig` + `curl -sI "$PRISM_BASE_URL/admin/integrations"`
returns a 302 redirect to `/login` when unauthenticated (`$PRISM_BASE_URL` = your local
base URL from `docker-compose.yml`; do not hardcode it anywhere).
Then regenerate the routes baseline:
`docker compose exec app php bin/console debug:router > var/refactor/routes-baseline.txt`.

Commit: `feat(admin): add /admin/integrations overview`.

### Phase 4 — Simple integrations (19×, one commit each)

Apply the Phase 2 recipe to, in order:

Alertmanager, Atlas, Browserless, Bunq, Calendar, Freescout, Ga4, GitHub, Igdb,
Libredesk, Loki, Matomo, N8n, OpenAi, Picnic, Prometheus, SendGrid, Tmdb, Transip.

Per-integration notes:

- **Bunq, Ga4, Igdb, Picnic, Transip**: `config/services.yaml` has an explicit service
  entry (`App\Bunq\BunqConfigLoader`, `App\Ga4\Ga4ConfigLoader`,
  `App\Igdb\IgdbService`, `App\Picnic\PicnicConfigLoader`,
  `App\Transip\TransipConfigLoader` — each with a `$projectDir` argument). The
  project-wide string replace in recipe step 3 updates these keys; double-check
  services.yaml afterwards.
- **Calendar**: files are `CalendarConfig.php` (not `CalendarAccountConfig`),
  `CalendarConfigLoader.php`, `CalendarService.php`. Keep names as-is.
- **GitHub**: dir name and namespace segment stay `GitHub` (capital H).
- Tool prefix collisions: none — every `{Prefix}*Tool.php` glob in §3.1 matches only
  that integration's tools (`Document*`, `Sum*`, `DayName*` are not integration
  prefixes and stay put).

After each integration: verification loop, then commit
`refactor({type}): move {type} integration to src/Integrations/{Dir}`.

### Phase 5 — Integrations with caches / stores / commands (3×)

Same recipe, plus:

- **Email** (14 src files):
  - `git mv src/Command/EmailWarmImapCacheCommand.php src/Integrations/Email/Command/`
    (create the `Command/` subdir; namespace → `App\Integrations\Email\Command`).
  - `config/services.yaml`: the `App\Email\MessageCache` entry (argument
    `$emailCache: '@cache.email'`) gets the new FQCN via the string replace — verify.
- **Slack**:
  - `config/services.yaml`: `App\Slack\SlackCache` entry (argument
    `$slackCache: '@cache.slack'`) — verify after replace.
- **Twilio**:
  - `git mv src/Command/TwilioTranscribeCallsCommand.php src/Integrations/Twilio/Command/`
    (namespace → `App\Integrations\Twilio\Command`). It imports
    `App\Whisper\WhisperService` — Whisper stays core, leave that import untouched.
  - `config/services.yaml`: `App\Twilio\TranscriptionStore` entry (`$projectDir`) —
    verify after replace.

### Phase 6 — Integrations with admin controllers / OAuth (3×)

Same recipe, plus:

- **Apify** — tools already live in a subdirectory:

```bash
git mv src/Apify src/Integrations/Apify
mkdir src/Integrations/Apify/Tool src/Integrations/Apify/Controller
git mv src/Mcp/Tool/Apify/*.php src/Integrations/Apify/Tool/
git mv src/Controller/ApifyAdminController.php src/Integrations/Apify/Controller/
```

  - Namespace rewrites: `App\Mcp\Tool\Apify` → `App\Integrations\Apify\Tool`;
    `App\Apify\` → `App\Integrations\Apify\`; the moved controller becomes
    `App\Integrations\Apify\Controller\ApifyAdminController` (it references
    `AbstractApifyActorTool` and `ToolInterface` — fix imports).
  - These tool files already import `App\Mcp\Tool\ToolInterface` where needed (they
    were in a subnamespace); verify each file compiles via the verification loop.
  - Remove the now-empty `src/Mcp/Tool/Apify/` dir.
  - Route `admin_server_apify` and template `templates/admin/apify/hub.html.twig`
    stay unchanged (templates don't move; route loading for `src/Integrations/` was
    added in Phase 1).
- **Canva**:
  - `git mv src/Controller/CanvaAdminController.php src/Integrations/Canva/Controller/`
    (namespace → `App\Integrations\Canva\Controller`). It contains the OAuth routes
    incl. `/admin/canva/callback` (`admin_canva_callback`) — paths and names must not
    change (routes-baseline diff catches this).
  - `config/services.yaml`: `App\Canva\CanvaTokenStore` entry (`$configPath`) —
    verify after replace. `CanvaTokenStore` writes tokens back into the local prism
    config file; behavior unchanged, only the namespace moves.
- **Instagram**:
  - `config/services.yaml`: `App\Instagram\InstagramTokenStore` entry (`$configPath`)
    — verify after replace.

### Phase 7 — Doctrine-backed integrations (2×)

Extra verification for both (§6.3 Doctrine block): `doctrine:mapping:info` must list
13 entities and the schema diff must be empty.

- **Habits**:
  1. `git mv src/Habits src/Integrations/Habits` (brings `Entity/` along).
  2. `mkdir src/Integrations/Habits/Controller src/Integrations/Habits/Command`, then
     `git mv src/Controller/HabitsAdminController.php src/Controller/HabitsApiController.php src/Integrations/Habits/Controller/`
     and `git mv src/Command/HabitsProcessCheckInsCommand.php src/Integrations/Habits/Command/`.
  3. Namespace rewrites: `App\Habits\` → `App\Integrations\Habits\` project-wide
     (covers `App\Habits\Entity\…` imports too); controllers/command get their new
     subnamespaces.
  4. `config/packages/doctrine.yaml` — update the existing `Habits` mapping:
     `dir: '%kernel.project_dir%/src/Integrations/Habits/Entity'`,
     `prefix: 'App\Integrations\Habits\Entity'` (alias stays `Habits`).
  5. Tools (15) via the standard recipe; `HabitsIntegration` metadata class; verify;
     commit.
- **Tracking**:
  1. `git mv src/Tracking src/Integrations/Tracking`.
  2. `mkdir src/Integrations/Tracking/Entity src/Integrations/Tracking/Repository src/Integrations/Tracking/Controller`.
  3. `git mv src/Entity/{GpsSample,TrackingDevice,TrackingZone,ZoneEvent}.php src/Integrations/Tracking/Entity/`
     and `git mv src/Repository/{GpsSampleRepository,TrackingDeviceRepository,TrackingZoneRepository,ZoneEventRepository}.php src/Integrations/Tracking/Repository/`.
  4. `git mv src/Controller/TrackingAdminController.php src/Controller/TrackingIngestController.php src/Integrations/Tracking/Controller/`.
  5. Namespace rewrites: `App\Tracking\` → `App\Integrations\Tracking\`; the four
     entities `App\Entity\X` → `App\Integrations\Tracking\Entity\X`; the four
     repositories `App\Repository\XRepository` → `App\Integrations\Tracking\Repository\XRepository`.
     Update every reference project-wide (entities carry
     `#[ORM\Entity(repositoryClass: …::class)]` attributes — imports must follow).
     There are no DQL strings or discriminator maps with hardcoded FQCNs (verified),
     so class moves are schema-safe.
  6. `config/packages/doctrine.yaml` — add a mapping alongside the existing ones:

```yaml
            TrackingEntity:
                type: attribute
                is_bundle: false
                dir: '%kernel.project_dir%/src/Integrations/Tracking/Entity'
                prefix: 'App\Integrations\Tracking\Entity'
                alias: Tracking
```

  7. `src/Entity/` must now contain only `Document.php`, `DocumentNote.php`,
     `DocumentType.php`; `src/Repository/` only the three Document repositories.
  8. Tools (5) via the standard recipe; `TrackingIntegration`; verify (incl. a curl to
     the public tracking ingest route still resolving — check `debug:router` diff);
     commit.

### Phase 8 — Cleanup, docs, final acceptance

1. **Leftover scan** (all must return nothing):

```bash
rg -n 'App\\(Alertmanager|Apify|Atlas|Browserless|Bunq|Calendar|Canva|Cyans|Email|Freescout|Ga4|GitHub|Habits|Igdb|Instagram|Libredesk|Loki|Matomo|N8n|OpenAi|Picnic|Prometheus|SendGrid|Slack|Tmdb|Tracking|Transip|Twilio)\\' src config templates
ls -d src/{Alertmanager,Apify,Atlas,Browserless,Bunq,Calendar,Canva,Cyans,Email,Freescout,Ga4,GitHub,Habits,Igdb,Instagram,Libredesk,Loki,Matomo,N8n,OpenAi,Picnic,Prometheus,SendGrid,Slack,Tmdb,Tracking,Transip,Twilio} 2>/dev/null
ls src/Mcp/Tool/         # exactly the 7 files from §3.2
ls src/Command/          # exactly McpToolsListCommand.php
```

2. **`/admin/integrations` regression guard**: the "unregistered types" section must
   be empty (all 28 registered).
3. **Docs** — update in one commit (`docs: update project docs for src/Integrations layout`):
   - `AGENTS.md`: project-structure tree, "How to Add a New Integration" (now: create
     `src/Integrations/{Name}/` with AccountConfig + ConfigLoader + Service +
     `{Name}Integration` + `Tool/` classes; no registration needed), quick-reference
     table.
   - `.cursor/rules/admin-feature-hub.mdc`: update the referenced paths for
     `HabitsAdminController` / `TrackingAdminController`.
   - `README.md`: check with `rg -n "src/" README.md` and update any moved paths.
4. **Full verification loop** one last time + spot-check MCP over HTTP with a real
   server (read the bearer token from the local gitignored config; never write it to
   any file):

```bash
curl -s -X POST "$PRISM_BASE_URL/mcp/<serverName>" \
  -H "Authorization: Bearer <token from local config>" -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | jq '.result.tools | length'
```

   The count must equal the number of tools that server had before the refactor.
5. Ask the user to click through the admin UI (dashboard, a server's tools tab, a tool
   "Try It", habits pages, tracking pages, apify/canva hubs, the new integrations
   pages) before considering the refactor done.

---

## 8. What Phases 0–8 explicitly do NOT do (out of scope for the mechanical move)

- No renames of classes, tools, account types, or routes.
- No template moves; no per-integration Twig namespaces.
- No changes to `Whisper`, `AgentNotify`, or the Document feature (they stay core;
  making Documents an "integration" is a possible follow-up).
- No new functionality besides the integrations pages and the `mcp:tools` command.
- No test suite (none exists); verification is via the console harness + diffs.
- No changes to the sidebar per-server nav (Canva/Apify/Habits/GPS items keep working
  because route names are unchanged).
- **No Action methodology implementation yet** — do not add `linkorb/action-component`
  / `action-bundle`, do not convert `ToolInterface` to Actions, do not add REST/CLI
  action transports in Phases 0–8. That is Phase 9 (§11). Still: keep module
  boundaries ActionPack-friendly (§1.1).

## 9. Integration metadata (use verbatim in the `*Integration` classes)

| Type | Label | Description |
|---|---|---|
| `alertmanager` | Alertmanager | Prometheus Alertmanager — inspect alerts, alert groups, silences and status; create and expire silences. |
| `apify` | Apify | Apify actor platform — run scraping actors (Instagram, LinkedIn, web search) and fetch results. |
| `atlas` | Atlas | Atlas content repositories — browse, search and read structured markdown content. |
| `browserless` | Browserless | Headless Chrome via Browserless — screenshots, PDFs, page content and performance metrics. |
| `bunq` | bunq | bunq online banking — list accounts and transactions, read transaction details and notes. |
| `calendar` | Calendar | ICS calendars — list calendars and events, read event details. |
| `canva` | Canva | Canva designs — list designs and read design pages via OAuth-connected accounts. |
| `cyans` | Cyans | Cyans topic tracking — list, search and read topics; add posts. |
| `email` | Email | IMAP/SMTP email — search, read, send, move and flag messages across folders. |
| `freescout` | FreeScout | FreeScout helpdesk — mailboxes, conversations, users and replies. |
| `ga4` | Google Analytics 4 | GA4 Data API — run reports and read property metadata. |
| `github` | GitHub | GitHub — account activity and issue/PR search. |
| `habits` | Habits | Habit tracking — users, habits, check-ins, events and scoreboards (database-backed). |
| `igdb` | IGDB | IGDB games — look up and search games; normalized metadata with absolute cover URLs. |
| `instagram` | Instagram | Instagram Graph API — media, comments, insights, publishing and account discovery. |
| `libredesk` | Libredesk | Libredesk helpdesk — conversations, drafts, notes, statuses, agents and teams. |
| `loki` | Loki | Grafana Loki — LogQL queries, labels and label values. |
| `matomo` | Matomo | Matomo analytics — sites, visit summaries, top pages and reports. |
| `n8n` | n8n | n8n workflow automation — inspect workflows and executions. |
| `openai` | OpenAI | OpenAI-compatible APIs — list models and run completions. |
| `picnic` | Picnic | Picnic online groceries — search products, manage the cart and deliveries. |
| `prometheus` | Prometheus | Prometheus — PromQL queries, metrics, alerts, rules and targets. |
| `sendgrid` | SendGrid | SendGrid — global, category and single-send email statistics. |
| `slack` | Slack | Slack workspaces — channels, messages, threads, reactions and posting. |
| `tmdb` | TMDb | The Movie Database — resolve IMDb ids, search movies/TV, fetch details, rate titles and manage the watchlist. |
| `tracking` | GPS Tracking | GPS tracking — devices, zones, traces and zone events (database-backed). |
| `transip` | TransIP | TransIP — domains, DNS records and invoices. |
| `twilio` | Twilio | Twilio — calls and call transcriptions. |

## 10. Progress checklist (executor: tick + commit as you go)

- [ ] Phase 0 — baseline + `mcp:tools`
- [ ] Phase 1 — scaffolding (interface, registry, wiring, routes)
- [ ] Phase 2 — pilot: Cyans
- [ ] Phase 3 — `/admin/integrations` UI
- [ ] Phase 4 — Alertmanager
- [ ] Phase 4 — Atlas
- [ ] Phase 4 — Browserless
- [ ] Phase 4 — Bunq
- [ ] Phase 4 — Calendar
- [ ] Phase 4 — Freescout
- [ ] Phase 4 — Ga4
- [ ] Phase 4 — GitHub
- [ ] Phase 4 — Igdb
- [ ] Phase 4 — Libredesk
- [ ] Phase 4 — Loki
- [ ] Phase 4 — Matomo
- [ ] Phase 4 — N8n
- [ ] Phase 4 — OpenAi
- [ ] Phase 4 — Picnic
- [ ] Phase 4 — Prometheus
- [ ] Phase 4 — SendGrid
- [ ] Phase 4 — Tmdb
- [ ] Phase 4 — Transip
- [ ] Phase 5 — Email
- [ ] Phase 5 — Slack
- [ ] Phase 5 — Twilio
- [ ] Phase 6 — Apify
- [ ] Phase 6 — Canva
- [ ] Phase 6 — Instagram
- [ ] Phase 7 — Habits
- [ ] Phase 7 — Tracking
- [ ] Phase 8 — cleanup + docs + final acceptance
- [ ] Phase 9 — Action methodology (north star; separate plan / user go-ahead) — §11

---

## 11. Phase 9 — Action methodology (follow-on; not started with Phases 0–8)

**Trigger:** only start after Phases 0–8 are done (or when the user explicitly
reprioritizes). This section is the agreed direction, not an executor runbook yet.

### 11.1 Goals

1. Adopt Nebula's **Action** contract (`linkorb/action-component`) as the reusable
   unit of capability — same actions runnable in Prism, Nebula, and other hosts.
2. Ship / consume integrations as **Composer packages** from:
   - **private** repos (personal / household packs),
   - **work-internal** repos (LinkORB / Nebula packs),
   - **public** repos (OSS packs others can reuse).
3. Keep Prism as the **multi-tenant host**: YAML servers, bearer tokens, account
   scoping, and MCP endpoints stay Prism's job; packs should not hardcode Prism
   tenants.
4. **Expose** registered actions over:
   - **MCP** (required — replace or wrap today's `ToolInterface` path),
   - **REST** (optional — thin execute/list API, similar to action-bundle HTTP),
   - **CLI** (optional — `action:list` / `action:view` / per-action commands).

### 11.2 Reference to study before implementing

In `linkorb/nebula` (private):

- `packages/action-component/` — `Action`, `ActionDefinition`, `ActionResult`,
  `ActionManager`, `ActionPackFactory`, `ConfigFactory`, attributes, hosts,
  executors. README states multi-transport intent (CLI, HTTP, MCP, …).
- `packages/action-bundle/` — Symfony wiring, Web UI, CLI loaders, HTTP
  `ActionController`, and especially `Controller/McpController.php` (builds an MCP
  server from `ActionManager` — closest analogue to Prism's `McpHandler` + tools).
- Example packs: `WeatherActionPack`, `LocationActionPack` (`action.pack` DI tag).

Prefer depending on published / path-repo packages over copying Nebula files into
Prism. If packages are only available from the Nebula monorepo, document the
Composer path/VCS source in Prism's `composer.json` (this *does* change
`composer.json` — unlike Phases 0–8).

### 11.3 Likely Prism design (sketch; refine when Phase 9 starts)

```
Composer packages (ActionPacks)
        │
        ▼
ActionManager  ←── ActionPackFactory / DI tag action.pack
        │
        ├── McpTransport     → existing /mcp/{serverName} (filter by server accounts)
        ├── RestTransport?   → e.g. /api/actions, /api/actions/{name}/execute
        └── CliTransport?    → action:* commands
```

Open decisions to resolve at Phase 9 kickoff (not now):

- Map Prism `account` / `type` into Action input context vs. pack-level config.
- Whether MCP tool names stay snake_case (`bunq_list_accounts`) or become dotted
  Action names (`bunq.list_accounts`) with a compatibility alias layer.
- Extract first pilot pack (likely a small read-only integration) into its own
  package and `composer require` it back into Prism.
- How much of `action-bundle` to reuse vs. a thin Prism-specific Symfony bridge
  (Prism already has admin UI + MCP auth; full Nebula UI may be overkill).

### 11.4 Success criteria (Phase 9)

- At least one integration runs as an ActionPack from an external package.
- That pack is invokable via MCP on a Prism server with the matching account type.
- Business logic of that pack has no dependency on `App\Mcp\Tool\ToolInterface`.
- Documented recipe: "add a pack from another repo" (private / internal / public).
- Optional: one non-MCP transport (CLI or REST) executes the same action.

### 11.5 Relationship to Phases 0–8

Phases 0–8 are **not wasted** under this north star: self-contained
`src/Integrations/{Name}/` directories are the right intermediate shape for later
extraction into packages / ActionPacks. Do not collapse everything into
`action-component` mid-move; finish the mechanical refactor first unless directed
otherwise.

## Appendix A — Recurring gotchas

1. Moved tools lose same-namespace access to `ToolInterface` — every moved tool file
   needs `use App\Mcp\Tool\ToolInterface;` (Apify's subdir tools already import it).
2. Ten explicit `services.yaml` entries reference classes that move (Bunq, Ga4,
   Picnic, Transip config loaders; IgdbService; Canva/Instagram token stores; Twilio
   TranscriptionStore; Email MessageCache; Slack SlackCache). The project-wide string
   replace covers them, but eyeball `config/services.yaml` after each such phase.
3. `lint:container` is the fastest breakage detector — run it before anything else.
4. Don't touch `prism.config.yaml.example` — it contains YAML config, not PHP FQCNs.
5. The DI resource `App\: resource: '../src/'` in services.yaml already covers
   `src/Integrations/` — no extra resource needed for autowiring; only routes need the
   Phase 1 addition (attribute controllers).
6. `git mv` may refuse when the target dir doesn't exist — always `mkdir` subdirs
   (`Tool/`, `Controller/`, `Command/`, `Entity/`, `Repository/`) first.
7. After moving files, stale compiled containers can mask errors — always
   `cache:clear` inside the container before judging success.
8. Habits entities have **no** repositories (service uses the EntityManager directly);
   Tracking has 4 repositories that move. Document repositories stay.
