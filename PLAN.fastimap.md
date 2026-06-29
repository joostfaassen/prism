# PLAN: Fast IMAP — a lightning-fast IMAP bridge for agents

## Goal

Make the `email` (IMAP) integration in Prism fast enough that an AI agent can
search across mailboxes and read many messages without the current
"open-one-message-at-a-time" latency. We stay **server-independent** — IMAP is
always the source of truth — but we add batching, connection reuse, and an
aggressive-yet-safe cache keyed on values IMAP itself guarantees.

---

## 1. Why it's slow today

The current implementation (`src/Email/ImapClient.php`) is correct but
pessimal for agent workloads. Two structural problems:

### 1a. A full connect/login/select/teardown per tool call

Every public method calls `connect()` → `imap_open()` and `imap_close()` in a
`finally`:

```329:344:src/Email/ImapClient.php
    private function connect(EmailAccountConfig $account, string $folder = 'INBOX'): \IMAP\Connection
    {
        $mailbox = $account->imap->getMailboxString($folder);
        $conn = @imap_open($mailbox, $account->imap->username, $account->imap->password, 0, 3);
```

A single `imap_open` against a TLS server is the expensive part: TCP handshake
+ TLS handshake + `LOGIN` + `SELECT`. An agent that searches once and then opens
8 messages pays that cost **9 times**, fully serialized.

### 1b. Multiple round trips per message, one message at a time

`search()` slices UIDs then loops, and for **each** UID calls
`fetchMessageSummary()`:

```136:139:src/Email/ImapClient.php
            $messages = [];
            foreach ($slice as $uid) {
                $messages[] = $this->fetchMessageSummary($conn, $uid);
            }
```

and each summary does **three** sequential server fetches:

```489:494:src/Email/ImapClient.php
        $header = $overview[0];
        $headerText = imap_fetchheader($conn, $uid, FT_UID);
        $parsedHeader = imap_rfc822_parse_headers($headerText);
        $structure = imap_fetchstructure($conn, $uid, FT_UID);
```

So a 20-result search = 1 connect + ~60 serialized fetches. single-message fetches
is a fresh connect + full body walk every time, even when re-reading the same
message the agent just listed.

There is **no caching and no batch API** anywhere in the email path. That is the
entire opportunity.

---

## 2. The IMAP guarantees we will exploit

These are protocol-level facts (RFC 3501 / RFC 7162), not server quirks, so
relying on them keeps us server-independent:

| Value | Source (cheap command) | Meaning we use |
|---|---|---|
| **UIDVALIDITY** | `SELECT` / `STATUS (UIDVALIDITY)` | If unchanged, every UID still maps to the *same* message. If it changes, the whole UID space is invalid → drop the folder's cache. |
| **UIDNEXT** | `STATUS (UIDNEXT)` | If unchanged, **no new message has arrived**. |
| **MESSAGES** | `STATUS (MESSAGES)` | Count change ⇒ messages added/expunged. |
| **HIGHESTMODSEQ** | `STATUS (HIGHESTMODSEQ)` (CONDSTORE, RFC 7162) | If unchanged, **no flag/keyword change** happened in the folder. Optional — only if the server advertises `CONDSTORE`. |

The single most important consequence:

> **A message body is immutable for a given `(account, folder, UIDVALIDITY, UID)`.**
> The bytes of `RFC822`/`BODYSTRUCTURE`/`ENVELOPE`/MIME parts never change while
> UIDVALIDITY is constant. Only **flags** (`\Seen`, `\Flagged`, keywords) mutate.

This lets us split every message into two cacheable halves with very different
policies:

- **Immutable half** (headers, parsed addresses, subject, date, body text/html,
  attachment metadata, structure) → cache **forever** under a UIDVALIDITY-scoped
  key. Never needs revalidation while UIDVALIDITY holds.
- **Mutable half** (the flag triplet `seen/flagged/answered` + keywords) → tiny,
  cheap to refetch, validated by HIGHESTMODSEQ (or just always refetched in one
  batched `FETCH (FLAGS)`).

---

## 3. Proposed architecture

Introduce three new collaborators alongside the existing `ImapClient`, wired by
Symfony autowiring (same pattern as the rest of the codebase):

```
src/Email/
├── ImapConnection.php      # one live, reusable connection (lifecycle + low-level fetch)
├── ImapConnectionPool.php  # request-scoped registry: account+folder -> ImapConnection
├── FolderSignature.php     # value object: uidvalidity/uidnext/messages/highestmodseq
└── MessageCache.php        # immutable-body + flag cache on top of a Symfony cache pool
```

`ImapClient` keeps its public surface but delegates connection handling to the
pool and read paths to `MessageCache`. `EmailService` gains the new batch
methods. Nothing about the *other* integrations changes.

### 3a. Connection reuse (biggest single win, zero correctness risk)

Within one MCP request, a batch tool may touch many UIDs and several folders.
Open the connection **once per (account, folder)** and reuse it for every fetch
in that request.

- `ImapConnectionPool` is a normal (request-scoped) service that lazily opens an
  `ImapConnection` the first time an `(account, folder)` is needed and closes all
  of them at the end of the request (kernel terminate / explicit `closeAll()`).
- Switching folders on the same account reuses the TCP+TLS+LOGIN session and only
  re-issues `SELECT` (or `imap_reopen()`), which is cheap.

> Cross-request pooling (a persistent IMAP daemon) is explicitly **out of scope**
> for phase 1 — PHP-FPM gives each MCP call a fresh process, and a daemon adds a
> lot of operational surface. The cache (3c) covers the cross-request case far
> more safely. We note it as a possible phase 4.

### 3b. Fewer round trips per message

For **summaries**, `imap_fetch_overview` already returns from/to/subject/date/
size **and** the flag booleans in a single call — we do not need
`imap_fetchheader` + `imap_fetchstructure` just to render a list row. We will:

- Build summaries from overview alone. The only field that needs structure is
  `has_attachments`; make it **lazy/optional** (a `with_attachments_flag` arg,
  default off for search) so the common list path is one fetch per batch, not
  three per message.
- Use **sequence-set fetches**: `imap_fetch_overview($conn, "101,102,103", FT_UID)`
  returns many overviews in **one** round trip instead of N. ext-imap accepts a
  comma/range UID list here, which is the core enabler for the multi-get tool.

### 3c. The cache layer (go heavy, but provably safe)

Use a dedicated Symfony cache pool (see §6 for backend). Two key families:

**Immutable body cache (long TTL, effectively permanent):**

```
key:  email.msg.{accountId}.{folderHash}.{uidValidity}.{uid}
val:  the full normalized message array (headers, body_text, body_html,
      attachments, structure-derived fields) — exactly what readMessage() builds
```

Because the key embeds `uidValidity`, a UIDVALIDITY change makes every old key
unreachable automatically — stale data can never be served. We can keep a very
long TTL (e.g. 30 days) purely as a size-bound, not for correctness.

**Folder signature cache (the invalidation oracle):**

```
key:  email.sig.{accountId}.{folderHash}
val:  FolderSignature { uidValidity, uidNext, messages, highestModSeq?, fetchedAt }
```

**Flag cache (short, cheap to rebuild):**

```
key:  email.flags.{accountId}.{folderHash}.{uidValidity}.{uid}
val:  { seen, flagged, answered, keywords[] }
```

#### Cache read/invalidation algorithm

On any read (`get`, `multi_get`, `search`):

1. **One `STATUS` call** per folder to read the live signature
   (`UIDVALIDITY UIDNEXT MESSAGES [HIGHESTMODSEQ]`). `imap_status()` already
   exposes `uidvalidity`, `uidnext`, `messages`, `unseen`, `recent` — see how it's
   used today in `listFolders()` (lines 48–55). This single command is the entire
   cost of validation.
2. Compare to the cached signature:
   - **UIDVALIDITY changed** → purge the folder (the body+flag keys are already
     orphaned by the new validity in the key; also drop the signature). Treat as
     cold.
   - **UIDVALIDITY same, UIDNEXT/MESSAGES same** → message set unchanged. Serve
     bodies straight from cache.
   - **HIGHESTMODSEQ present and unchanged** → flags unchanged too → serve the
     cached flag half as well, **zero per-message fetches**.
   - **HIGHESTMODSEQ changed (or unsupported)** → bodies still valid (immutable),
     but refetch the **flag** half in one batched `FETCH (FLAGS)` for the UIDs we
     return, and overwrite the flag cache.
3. For UIDs whose **body** half is missing from cache, batch-fetch only those
   (sequence-set) and populate.

This guarantees: **we never serve a body that doesn't belong to its UID**, and
**we never serve a stale flag** beyond the single STATUS check — invalidation is
one cheap command, exactly the "quick way to check the requirements for cache
invalidation" the brief asks for.

#### Write-through on mutations

`moveMessage` and `updateMessageflags` already know the UID(s) and folder they
touch. After a successful mutation:

- update/evict the affected flag-cache entry directly (write-through), and
- bump the locally cached signature so the next read revalidates.

This avoids a stale window between an agent flagging a message and re-reading it.

---

## 4. New & changed MCP tools (the agent-facing API)

All new tools follow the existing `ToolInterface` conventions (snake_case,
`email_` prefix, `getAccountType() === 'email'`, MCP content/isError return
shape).

### 4a. `email_get_messages` (multi-get) — NEW

Fetch many messages in one call.

```jsonc
{
  "account": "work-mail",
  "folder": "INBOX",
  "uids": [101, 104, 119, 200],
  "include_html": false,
  "max_body_chars": 8000
}
```

- One `SELECT` + one batched body fetch for the cache-miss UIDs + one batched
  `FETCH (FLAGS)`. Returns `{ messages: [...], misses: [...] }`.
- This single tool replaces the N-call "open messages one by one" pattern that
  dominates current latency.

### 4b. `email_multi_search` (multi-folder / multi-query) — NEW

Run one or more search specs, optionally across several folders, in one call
over a single reused connection.

```jsonc
{
  "account": "work-mail",
  "folders": ["INBOX", "Archive", "Sent"],
  "from": "boss@example.com",
  "unseen_only": true,
  "limit_per_folder": 20
}
```

Returns results grouped by folder. Internally: one connection, `SELECT` per
folder, `imap_search`, then a **single batched** overview fetch for the union of
returned UIDs — not three fetches per message.

### 4c. `email_search` / `email_get_messages` — CHANGED (transparent)

- `email_search` becomes cache-aware (signature check + batched overview) and
  gains an optional `with_attachments_flag` (default `false`) so the fast path
  skips structure fetches.
- `email_get_messages` reads through `MessageCache` (immutable body served from
  cache; flags validated via signature). API and output shape unchanged → fully
  backward compatible.

### 4d. `email_prefetch` (optional, opportunistic) — NEW, phase 3

A hint tool: `{ account, folder, uids: [...] }` warms the body cache without
returning payloads. Lets an agent kick off background warming right after a
search while it reasons about which message to open. Cheap to implement once the
cache exists; skip if it complicates the agent UX.

### 4e. Why multi-get is a big win (including folder+uid combos)

Yes — multi-get is one of the highest-impact changes because IMAP round trips
dominate latency far more than local CPU parsing.

**Why it helps:**

- **Fewer MCP calls:** without multi-get, an agent often does N single-message
  calls; with multi-get, one call carries N UIDs.
- **Fewer IMAP command cycles:** one selected folder can fetch many UIDs via a
  sequence-set instead of repeating single-message fetch pipelines.
- **Better cache behavior:** batch requests let us do one folder-signature check
  and then resolve many cache hits/misses in one pass.
- **Lower agent token/tool overhead:** agents spend less time planning repeated
  tool calls and more time reasoning on returned content.

**`uids` vs `folder+uid` combos:**

- **Same-folder multi-get (`{ folder, uids[] }`)** should be the default fast
  path. It is the cheapest IMAP shape because one `SELECT` can serve all UIDs.
- **Cross-folder multi-get (`[{folder, uid}, ...]`)** is still useful for agent
  ergonomics, but internally should be implemented as:
  1) group by folder, 2) process each folder as same-folder multi-get, 3) merge
  results in original request order.
- This keeps protocol efficiency while giving agents a single convenient tool
  for mixed result sets (e.g. from multi-search across folders).

**Recommended API shape:**

1. Keep `email_get_messages` for same-folder high-throughput reads.
2. Optionally add `email_get_messages_multi_folder` for ergonomic mixed-folder
   fetches, implemented as grouped same-folder batches under the hood.
3. Enforce per-call caps (e.g. max 100-250 message targets) to avoid oversized
   IMAP commands and to keep response payloads predictable.

So the short answer: **yes, absolutely helpful** — it is not just convenient,
it directly reduces round trips and is a core part of making IMAP feel
lightning-fast for agents.

---

## 5. Implementation phases

Ordered by value-per-effort. Each phase is independently shippable.

**Phase 1 — Connection reuse + batched fetch (no cache, no new storage).**
- Add `ImapConnection` + `ImapConnectionPool`; route `ImapClient` through them.
- Switch summaries to overview-only (lazy attachments), add sequence-set fetch.
- Ship `email_get_messages` and `email_multi_search`.
- *Expected effect:* the dominant win. Removes N× logins and 3× per-message
  fetches. No correctness risk, no invalidation to reason about.

**Phase 2 — Immutable body cache + signature invalidation.**
- Add `FolderSignature`, `MessageCache`, dedicated cache pool.
- Make `get`/`multi_get`/`search` cache-aware per §3c.
- Write-through eviction in `moveMessage` / `updateMessageFlags`.
- *Expected effect:* re-reads and repeat searches become near-instant; cold cost
  unchanged.

**Phase 3 — Flag fast-path + prefetch.**
- Use HIGHESTMODSEQ (when advertised) to skip flag refetches entirely.
- Optional `email_prefetch`.

**Phase 4 — (only if needed) cross-request connection daemon.**
- A small long-lived IMAP connection broker if profiling shows per-request login
  is still the bottleneck after caching. Likely unnecessary.

---

## 6. Storage / backend choices

- **Cache pool:** define a dedicated pool `cache.email` in
  `config/packages/cache.yaml` (currently all defaults/commented). Filesystem
  adapter is fine to start (bodies can be large; filesystem handles big values
  better than APCu). If a Redis provider is configured, point the pool at it for
  multi-process sharing. This is a config-only choice and stays optional.
- **No database needed** for this feature. Although Doctrine/`DATABASE_URL` exist
  in the project, the IMAP cache is pure key/value keyed on immutable identifiers
  — a cache pool is the right tool, and it keeps "IMAP is the source of truth"
  literally true (cache is always reconstructable and self-invalidating).
- **Key hashing:** folder names contain delimiters/unicode; hash them
  (`folderHash = substr(sha1(folder), 0, 16)`) for safe cache keys. Namespace all
  keys per `accountId` and include `uidValidity` so purges are implicit.
- **Size bounds:** cap cached `body_html`/`body_text` to the same
  `max_body_chars` the tools already enforce (lines 449–456) before storing;
  store attachment **metadata** only, never attachment bytes.

---

## 7. Server independence & correctness guarantees

- Every optimization relies only on standard IMAP (`SELECT`, `STATUS`, `UID
  FETCH`, `SEARCH`) and the RFC-defined `UIDVALIDITY`/`UIDNEXT`/`HIGHESTMODSEQ`
  semantics. No Gmail-, Dovecot-, or Exchange-specific behavior.
- HIGHESTMODSEQ (CONDSTORE) is treated as a **bonus**: if the server doesn't
  advertise it, phase 3 degrades gracefully to a single batched `FETCH (FLAGS)`
  per read — still one round trip.
- IMAP remains authoritative: a STATUS mismatch always wins over the cache, and
  bodies are only ever served under a matching UIDVALIDITY. The cache can be
  wiped at any time with no behavioral change beyond a cold fetch.

## 8. Risks & edge cases

- **Servers that recycle UIDs without bumping UIDVALIDITY** — non-conformant, but
  the STATUS (UIDNEXT/MESSAGES) check catches the common add/expunge cases; the
  immutable-body assumption holds for conformant servers (our stated contract).
- **Per-message overview missing flags on some servers** — fall back to the
  existing `imap_fetch_overview` fields already used today (lines 496–507); they
  are the same source.
- **Large mailboxes** — `email_multi_search` must cap `limit_per_folder` (reuse
  the existing `min($limit, 100)` guard at line 134) and the batched fetch must
  chunk very large UID sets to avoid oversized command lines.
- **`\Seen` side effects** — reading a body with `imap_fetchbody` can set
  `\Seen`. Use `FT_PEEK` on body fetches so caching/prefetch never silently marks
  mail read; expose `mark_seen` explicitly if an agent wants that.
- **Connection liveness** — `ImapConnection` must detect a dropped socket and
  reconnect once before failing a batch.

---

## 9. Summary of deliverables

| Item | Type | Phase |
|---|---|---|
| `ImapConnection`, `ImapConnectionPool` | new service | 1 |
| Overview-only summaries + sequence-set batch fetch | change to `ImapClient` | 1 |
| `email_get_messages` (multi-get) | new tool | 1 |
| `email_multi_search` (multi-folder) | new tool | 1 |
| `FolderSignature`, `MessageCache` + `cache.email` pool | new service + config | 2 |
| Cache-aware `email_search` / `email_get_messages` | change | 2 |
| Write-through eviction on move/flag updates | change | 2 |
| HIGHESTMODSEQ flag fast-path | enhancement | 3 |
| `email_prefetch` | optional tool | 3 |
| Cross-request connection broker | optional | 4 |

The headline: **reuse the connection, batch the fetches, and cache the
immutable message bodies under a UIDVALIDITY-scoped key validated by one cheap
`STATUS` call.** That turns the "search then open eight messages" workflow from
~9 logins and ~60 serialized fetches into a single login with one or two batched
round trips — and near-zero work on any repeat.
