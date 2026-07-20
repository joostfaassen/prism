# PLAN: Email drafts — stage and delete IMAP drafts like a real mail client

## Goal

Add two MCP tools to the `email` integration:

1. **`email_create_draft`** — compose a message (optionally as a reply to an
   existing `folder` + `uid`) and store it in the account's IMAP **Drafts**
   folder, byte-for-byte the way Thunderbird saves a draft. The human then
   opens their mail client, reviews, optionally edits, and hits Send.
2. **`email_delete_draft`** — permanently remove a draft from the Drafts
   folder (so a corrected one can be staged afterwards).

This gives agents a low-risk "prepare, don't send" workflow next to
`email_send`, mirroring what `libredesk_upsert_draft` / `libredesk_delete_draft`
already provide for Libredesk.

**Explicitly out of scope:** an update/upsert tool (replace = delete + create),
attachments (also unsupported by `email_send`), and a read/list tool — drafts
are ordinary IMAP messages, so `email_search`, `email_get_messages` and
`email_list_folders` on the Drafts folder already cover reading and listing.

---

## 1. What exists today (all the building blocks are in place)

| Building block | Where | State |
|---|---|---|
| `drafts_folder` account config (default `"Drafts"`) | `EmailConfigLoader` → `EmailAccountConfig::$draftsFolder` | **Already parsed and documented** (docs/email.md §1), but unused by any code path today. |
| APPEND to an arbitrary folder, auto-creating it, CRLF-normalized, with flags | `ImapClient::appendToFolder()` | Exists — used for the Sent copy with `'\\Seen'`. Takes a `$flags` string, so `'\\Seen \\Draft'` works as-is. Returns `void` (no UID). |
| Full compose pipeline: markdown → text+HTML multipart, From/identity, Message-ID, Date, threading headers, quoted original | `MessageComposer::compose()` + `ReplyContext` | Exists — exactly what `email_send` uses. Message-ID and Date are fixed at compose time, so we know the Message-ID *before* materializing. |
| Reply-recipient derivation (reply vs reply-all, skip self) | `EmailService::sendMessage()` lines ~274–295 + `pickPrimaryReplyAddress()` + `MessageComposer::buildReplyAllRecipients()` | Exists inline in `sendMessage()` — should be extracted into a private helper so `createDraft()` can share it. |
| Message deletion | — | **Missing.** `ImapClient` has move + flag update, but no delete/expunge primitive. |
| UID of an appended message | — | **Missing.** ext-imap's `imap_append()` does not expose the UIDPLUS `APPENDUID` response. See §4a for the resolution strategy. |

Conclusion: this is a thin feature. No new config, no new account type, no
database. Two new tools, two new `EmailService` methods, two focused
`ImapClient` changes, one Bcc subtlety in materialization.

---

## 2. The interop contract: how Thunderbird saves a draft

What "identical to Thunderbird" concretely means (and what we will match):

1. **A draft is a complete RFC 5322/MIME message** — the same bytes that
   would eventually go out over SMTP — `APPEND`ed to the Drafts folder.
2. **Flags: `\Draft` and `\Seen`** are set on the appended message. Mail
   clients use `\Draft` (plus the folder's special-use role) to render the
   message as editable instead of received.
3. **Bcc is preserved in the draft.** This is the one place drafts differ
   from sent mail: the draft is never transmitted, and the client must be
   able to restore the Bcc field when the user reopens it. Thunderbird
   stores the `Bcc:` header in the draft and strips it only on actual send.
   ⚠️ Symfony Mime's `Message::toString()` → `getPreparedHeaders()`
   **removes Bcc** (`vendor/symfony/mime/Message.php`, "remove the Bcc field
   which should NOT be part of the sent message") — correct for
   `email_send`'s Sent copy, wrong for drafts. §4c handles this.
4. **Reply drafts are threaded at save time.** `In-Reply-To` + `References`
   headers are present in the draft, the subject gets `Re:` prefixed, and
   the quoted original is already in the body — because compose happens
   when the draft is written, not when it is sent. Our existing
   `MessageComposer` + `ReplyContext` produce exactly this.
5. **The original message is *not* marked `\Answered`** when a draft is
   saved — that only happens on real send. (Prism's `email_send` doesn't
   set it either, so: do nothing.)
6. **Re-saving a draft = append new + delete old.** Thunderbird appends the
   edited version and marks the previous one `\Deleted` (expunged on
   compact/close). We model this explicitly as `email_delete_draft`
   followed by `email_create_draft`, per the user workflow.
7. **What we deliberately skip:** Thunderbird's private headers
   (`X-Mozilla-Draft-Info`, `X-Identity-Key`, `FCC`, `X-Mozilla-Status…`).
   They are client-internal bookkeeping; drafts written without them (by
   webmail, Apple Mail, etc.) open fine in Thunderbird. Adding fake Mozilla
   headers would be cargo-culting. We keep the existing
   `X-Mailer: Prism MCP Bridge` header.

---

## 3. Tool design (agent-facing API)

Both tools follow existing conventions: snake_case `email_` prefix,
`getAccountType() === 'email'`, MCP `content`/`isError` return shape,
validation at the top of `execute()`.

### 3a. `email_create_draft`

Input schema — deliberately a near-clone of `email_send` minus the
send/Sent-copy options, plus a `drafts_folder` override:

```jsonc
{
  "account": "personal-mail",          // required
  "to": "alice@example.com",           // string | string[], optional
  "cc": ["bob@example.com"],           // string | string[], optional
  "bcc": "carol@example.com",          // string | string[], optional — PRESERVED in the draft
  "subject": "Lunch tomorrow?",        // optional; replies default to "Re: <original>"
  "body_markdown": "Hey Alice, …",     // required — rendered to text+HTML like email_send
  "from_name": "…",                    // optional From display-name override
  "reply_to_address": "…",             // optional Reply-To header
  "reply_to": {                        // optional reply context
    "folder": "INBOX",
    "uid": 4821,
    "reply_all": false
  },
  "drafts_folder": "INBOX.Drafts"      // optional override; default = account's drafts_folder
}
```

Behavioral notes:

- **No SMTP required.** Draft creation is pure IMAP, so read-only accounts
  (no `smtp:` block) can stage drafts too — the human sends from their own
  client. Do **not** copy `sendMessage()`'s `hasSmtp()` guard.
- **Recipients may be empty** for a non-reply draft (a human can fill them
  in later; Thunderbird allows recipient-less drafts). For replies, when
  `to`/`cc` are omitted, derive them from the original exactly like
  `email_send` does (including `reply_all`).
- Reply handling is identical to `email_send`: fetch original via
  `ImapClient::getMessageForReply()`, build `ReplyContext`, thread + quote.

Result (JSON-encoded in the text content, like `email_send`):

```jsonc
{
  "created": true,
  "folder": "Drafts",
  "uid": 4903,                  // UID in the drafts folder, or null (see §4a)
  "message_id": "abc123…@example.com",
  "subject": "Re: Lunch tomorrow?",
  "to": ["alice@example.com"],
  "cc": [],
  "bcc": [],
  "in_reply_to": "original-id@example.com",   // null for fresh drafts
  "warning": "…"                // only when uid could not be resolved
}
```

Description must state (mirroring `LibredeskUpsertDraftTool`): the draft is
**never sent automatically** — it appears in the user's mail client's Drafts
folder for review; to replace an existing draft, delete it first with
`email_delete_draft` and create a new one.

### 3b. `email_delete_draft`

```jsonc
{
  "account": "personal-mail",          // required
  "uid": 4903,                         // required — UID within the drafts folder
  "drafts_folder": "INBOX.Drafts",     // optional override; default = account's drafts_folder
  "expected_message_id": "abc123…@example.com"  // optional safety guard
}
```

Behavior:

- Operates **only** on the (configured or overridden) drafts folder — there
  is no free `folder` parameter pointing at INBOX, on purpose.
- Fetch the message overview first and **refuse unless the `\Draft` flag is
  set** on the message. This keeps the tool from deleting regular mail even
  if someone points `drafts_folder` at the wrong folder.
- If `expected_message_id` is given and doesn't match the message's
  Message-ID, refuse (guards against UID mix-ups between agent turns).
- Delete = `imap_delete(…, FT_UID)` + `imap_expunge()`. Note: `imap_expunge`
  expunges *all* `\Deleted` messages in the folder — same accepted semantics
  as the existing `ImapClient::moveMessage()`; harmless in a drafts folder.

Result:

```jsonc
{
  "deleted": true,
  "folder": "Drafts",
  "uid": 4903,
  "message_id": "abc123…@example.com",
  "subject": "Re: Lunch tomorrow?"
}
```

Description must state this is permanent (no trash), affects only staged
drafts, and is the first step of the "replace a draft" workflow.

---

## 4. Implementation details

### 4a. `ImapClient` — return the new UID from APPEND, add delete

**Problem:** ext-imap's `imap_append()` returns only `bool`; the UIDPLUS
`APPENDUID` response is not exposed. Without the UID the agent can't target
the draft for deletion later.

**Strategy (standard IMAP, server-independent):** UIDs within a folder are
strictly increasing while UIDVALIDITY is constant. So:

1. Before APPEND: `imap_status($conn, $folderPath, SA_UIDNEXT)` → `uidFrom`.
2. APPEND the message.
3. `imap_reopen($conn, $folderPath)` (the connection was opened on INBOX;
   overview fetches operate on the *selected* mailbox), then fetch
   `imap_fetch_overview($conn, "{uidFrom}:*", FT_UID)` and pick the entry
   whose `message_id` equals the Message-ID we composed (compose fixes
   Message-ID before materialization, so it is known). Matching by
   Message-ID makes this robust against a concurrent append by another
   client.
4. If resolution fails (no status support, empty overview, no match):
   return `null` — the tool then responds with `uid: null` plus a warning
   telling the agent to locate the draft via `email_search` on the drafts
   folder. Never fail the whole call over UID resolution: the draft *was*
   stored.

Signature change (Sent-copy call site stays behaviorally identical):

```php
public function appendToFolder(
    EmailAccountConfig $account,
    string $folder,
    string $rawMessage,
    string $flags = '\\Seen',
    ?string $expectedMessageId = null,   // when non-null, resolve and return the new UID
): ?int
```

Existing caller in `EmailService::sendMessage()` passes no
`$expectedMessageId` and ignores the return value → zero extra round trips
on the send path.

New method:

```php
/** @return array<string, mixed>  overview summary of the deleted message */
public function deleteMessage(
    EmailAccountConfig $account,
    string $folder,
    int $uid,
    bool $requireDraftFlag = true,
    ?string $expectedMessageId = null,
): array
```

- `connect($account, $folder)`, `imap_fetch_overview((string) $uid, FT_UID)`
  → not found ⇒ clear `\RuntimeException`.
- `$requireDraftFlag` and the overview's `draft` property enforce §3b's
  guard; `$expectedMessageId` compared against the normalized
  `message_id` (reuse `normalizeMessageId()`).
- `imap_delete($conn, (string) $uid, FT_UID)` + `imap_expunge($conn)`, with
  the same error style as `moveMessage()`.
- Cache hygiene: call `MessageCache::deleteMessageFlags()` for the UID
  (mirrors nothing today but is cheap). Body/pointer entries are keyed on
  UIDVALIDITY+UID which the server never reuses, so they become unreachable
  on their own — no further invalidation needed. Folder signatures are
  re-validated live (`refreshFolderSignature()`) on every read path, so
  UIDNEXT/MESSAGES changes from our append/delete are picked up
  automatically.

### 4b. `EmailService` — `createDraft()` / `deleteDraft()` + shared reply helper

Extract the reply-resolution block currently inlined in `sendMessage()`
(lines ~272–295: fetch original → `ReplyContext::fromImapMessage()` →
derive `to`/`cc` when omitted) into a private helper, e.g.:

```php
/**
 * @return array{context: ?ReplyContext, to: list<string>, cc: list<string>}
 */
private function resolveReply(EmailAccountConfig $account, ?array $replyTo, array $to, array $cc): array
```

`sendMessage()` keeps its behavior (including the "at least one recipient"
check — that check stays in `sendMessage()`, *not* in the helper, because
drafts allow zero recipients).

```php
public function createDraft(
    string $accountId,
    array $to, array $cc, array $bcc,
    ?string $subject,
    string $bodyMarkdown,
    ?string $fromName,
    ?string $replyToOverride,
    ?array $replyTo,                 // {folder, uid, reply_all?} like sendMessage
    ?string $draftsFolderOverride,
): array
```

Flow: `getAccount()` → `resolveReply()` → `MessageComposer::compose()` →
materialize **with Bcc** (§4c) → `appendToFolder($account, $draftsFolder,
$raw, '\\Seen \\Draft', $messageId)` → build result array (§3a).
`$draftsFolder = $draftsFolderOverride ?? $account->draftsFolder`.

```php
public function deleteDraft(
    string $accountId,
    int $uid,
    ?string $draftsFolderOverride,
    ?string $expectedMessageId,
): array
```

Thin delegation to `ImapClient::deleteMessage(…, requireDraftFlag: true)`,
shaping the §3b result from the returned overview summary.

### 4c. Materializing the draft with Bcc intact

`Email::toString()` strips Bcc via `getPreparedHeaders()`. For drafts,
build the raw bytes manually (private helper in `EmailService`, next to the
existing `extractMessageId()`):

```php
private function materializeDraft(Email $email): string
{
    $headers = $email->getPreparedHeaders();      // adds MIME-Version/Date/…, removes Bcc

    if ($email->getBcc() !== []) {
        $headers->addMailboxListHeader('Bcc', $email->getBcc());  // put it back — drafts keep Bcc
    }

    return $headers->toString() . $email->getBody()->toString();
}
```

Message-ID and Date are already set by `MessageComposer::compose()`, so
`getPreparedHeaders()` won't regenerate them — the Message-ID used for UID
resolution is stable. CRLF normalization already happens inside
`appendToFolder()`.

### 4d. Tool classes

`src/Mcp/Tool/EmailCreateDraftTool.php` and
`src/Mcp/Tool/EmailDeleteDraftTool.php`, modeled on `EmailSendTool`:

- Reuse (copy) `EmailSendTool`'s `normalizeRecipients()` and `error()`
  private helpers and its `reply_to` argument validation. (Optional tidy-up:
  a small shared trait for `normalizeRecipients()` — only if the
  implementer prefers; duplication of ~30 lines is also acceptable in this
  codebase's current style.)
- `EmailCreateDraftTool::execute()` validates `account` + `body_markdown`
  (non-empty), `reply_to.folder`/`reply_to.uid` when present — but **not**
  "at least one recipient".
- `EmailDeleteDraftTool::execute()` validates `account` and integer
  `uid > 0`.
- No registration needed — DI auto-discovers `ToolInterface` implementations.

---

## 5. Edge cases & risks

- **Drafts folder name varies per provider** (`Drafts`, `INBOX.Drafts`,
  `[Gmail]/Drafts`, `Concepten`, …). Handled by the existing
  `drafts_folder` account config + the per-call override; `appendToFolder()`
  already auto-creates missing folders. The tool description should point
  agents to `email_list_folders` when in doubt.
- **Gmail quirk:** appending to `[Gmail]/Drafts` is the documented way to
  create Gmail drafts over IMAP; Gmail treats messages in that folder as
  drafts regardless of flags. No special-casing needed.
- **UID resolution race:** matching on our own Message-ID within the
  `uidFrom:*` window makes a concurrent append by another client harmless.
  Worst case → `uid: null` + warning, never a wrong UID.
- **`imap_expunge` folder-wide semantics:** deleting one draft also expunges
  any messages *already* flagged `\Deleted` by other clients in that folder.
  Accepted (identical to `moveMessage()` today); scope is limited to the
  drafts folder.
- **Empty-recipient drafts:** valid per §3a. `Email::ensureValidity()` is
  only invoked by mailer transports, not by header/body materialization, so
  composing without recipients works.
- **HTML+text alternative body:** we store the same multipart/alternative
  the send path produces. Thunderbird edits drafts in its HTML or plain
  composer depending on user settings and handles multipart drafts fine.
- **Don't set `\Answered`, don't touch the original message** on draft
  creation (§2.5).
- **Approval posture:** creating a draft is intentionally low-risk (nothing
  is sent) — clients need not gate it as heavily as `email_send`. Deleting
  a draft is destructive but narrowly scoped. Word both descriptions
  accordingly; do not copy `email_send`'s "typically requires user
  approval" line onto `email_create_draft`.

---

## 6. File-by-file change list

| File | Change |
|---|---|
| `src/Email/ImapClient.php` | `appendToFolder()` returns `?int` + optional `$expectedMessageId` (UIDNEXT-window + Message-ID match); new `deleteMessage()` with draft-flag/Message-ID guards + flag-cache eviction. |
| `src/Email/EmailService.php` | New `createDraft()` + `deleteDraft()`; extract `resolveReply()` from `sendMessage()`; new `materializeDraft()` (Bcc-preserving). `sendMessage()` behavior unchanged. |
| `src/Email/ImapClient.php` (`listAccountSummary()`) | Add `drafts_folder` next to the existing `sent_folder` so agents can see where drafts land. |
| `src/Mcp/Tool/EmailCreateDraftTool.php` | New tool `email_create_draft` (§3a). |
| `src/Mcp/Tool/EmailDeleteDraftTool.php` | New tool `email_delete_draft` (§3b). |
| `README.md` | Two new rows in the Email tools table. |
| `docs/email.md` | New section "Staging drafts" (workflow: create → review in mail client → send there; or delete → recreate), tool reference, note that `email_search`/`email_get_messages` on the Drafts folder read drafts. |
| `AGENTS.md` | Add the two tool classes to the project-structure listing. |

No changes to: config loading (drafts_folder already parsed), security,
`McpHandler`, `MessageComposer` (compose is reused as-is), Sent-copy
behavior of `email_send`.

---

## 7. Suggested implementation order (atomic commits)

1. `ImapClient::appendToFolder()` UID return + `deleteMessage()` primitive.
2. `EmailService`: `resolveReply()` extraction (pure refactor, no behavior
   change to `email_send`).
3. `EmailService::createDraft()` + `materializeDraft()` +
   `EmailCreateDraftTool`.
4. `EmailService::deleteDraft()` + `EmailDeleteDraftTool`.
5. `listAccountSummary()` `drafts_folder` addition.
6. Docs (README, docs/email.md, AGENTS.md).

---

## 8. Manual verification checklist (no test suite exists yet)

Using a dev account and the admin "Try It" page (or an MCP client):

1. **Fresh draft:** `email_create_draft` with to+cc+bcc+subject+markdown →
   open the mailbox in Thunderbird → draft appears in Drafts, opens in the
   composer with all recipients **including Bcc**, subject and body intact;
   sending it from Thunderbird works and clears the draft.
2. **Reply draft:** create with `reply_to: {folder: INBOX, uid: …}` and no
   recipients → recipients derived from the original; opened draft shows
   `Re:` subject and quoted original; after sending from Thunderbird the
   reply threads correctly in the recipient's client (In-Reply-To /
   References preserved).
3. **Reply-all draft:** `reply_all: true` → To/Cc match Thunderbird's own
   reply-all for the same message (self excluded).
4. **UID round-trip:** the `uid` returned by create resolves via
   `email_get_messages` on the Drafts folder; `email_delete_draft` with that
   uid removes it (verify in Thunderbird after folder refresh).
5. **Guards:** delete with a wrong `expected_message_id` refuses; delete of
   a non-draft message (e.g. after moving a regular mail into Drafts
   without `\Draft`) refuses.
6. **Read-only account:** create a draft on an account without `smtp:` —
   must succeed.
7. **Folder override/auto-create:** `drafts_folder` override pointing at a
   not-yet-existing folder gets created and used.
