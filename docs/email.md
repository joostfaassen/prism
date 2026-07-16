# Email Integration

Prism's `email` account type bridges a regular mailbox over the Model Context Protocol — letting an AI client list folders, search messages, read content, **stage drafts** in the Drafts folder, and **send replies** that are correctly threaded and saved to the Sent folder, exactly like a normal email client (Thunderbird, Apple Mail, …) would do.

Each `email` account combines:

- **IMAP** for reading mail and writing the Sent copy
- **SMTP** for outbound delivery (optional — accounts without SMTP are read-only)
- An **identity** (From address + display name) used when composing

## 1. Configure an account

Add an `email` account to any server in `prism.config.yaml`:

```yaml
servers:
  my-server:
    label: "My Server"
    bearer_token: "your-prism-bearer-token"
    accounts:
      personal-mail:
        type: email
        label: "Personal mailbox"
        imap:
          host: "imap.example.com"
          port: 993
          encryption: ssl          # ssl | tls | none
          username: "user@example.com"
          password: "app-specific-password"
          validate_cert: true
          sent_folder: "Sent"      # optional, defaults to "Sent"
          drafts_folder: "Drafts"  # optional, defaults to "Drafts"
        smtp:
          host: "smtp.example.com"
          port: 465                # 465 = ssl, 587 = starttls
          encryption: ssl          # ssl | starttls | none
          username: "user@example.com"   # optional, defaults to imap.username
          password: "app-specific-password"  # optional, defaults to imap.password
          validate_cert: true
        identity:
          email: "user@example.com"
          name: "Display Name"
          # reply_to: "team@example.com"   # optional Reply-To header
```

### What's required

| Block      | Required | Notes |
|------------|----------|-------|
| `imap`     | Yes      | Always required — used for reading and for saving the Sent copy. |
| `smtp`     | No (but required by `email_send`) | Without it, the account can still read mail and stage drafts; only sending is disabled. |
| `identity` | No       | Defaults to `imap.username`. Set it explicitly when the SMTP login differs from your "From" address (common for shared mailboxes or aliases). |

### Encryption modes

- **IMAP `ssl`** → implicit TLS on port 993 (recommended).
- **IMAP `tls`** → STARTTLS on port 143.
- **SMTP `ssl`** → implicit TLS on port 465 (recommended).
- **SMTP `starttls`** → STARTTLS upgrade on port 587.

If a server presents a self-signed or otherwise unverifiable certificate, set `validate_cert: false`. Keep it `true` for any production-grade provider.

## 2. Available tools

Once an account is configured, the server exposes these tools:

| Tool | Purpose |
|------|---------|
| `email_list_accounts` | List configured accounts and whether each can send mail. |
| `email_list_folders`  | List IMAP folders with total/unread counts. |
| `email_list_labels`   | List folders, standard flags, and custom IMAP keyword tags found in a folder. |
| `email_search`        | Search a folder by sender, recipient, subject, body, date range, flags. Soft-deleted (`\\Deleted`) messages are excluded by default. |
| `email_get_messages`  | Fetch one or many messages (headers, text/HTML body, attachments metadata, threading info). Summaries/bodies include `deleted` when the IMAP `\\Deleted` flag is set. |
| `email_get_message_labels` | Read standard flags and custom keyword tags/labels on one message by UID. |
| `email_update_message_flags` | Set/clear standard flags and add/remove custom keyword tags (`add_labels` / `remove_labels`). |
| `email_send`          | Send a new message *or* a properly threaded reply. |
| `email_create_draft`  | Stage a draft in the IMAP Drafts folder (never sends). |
| `email_delete_draft`  | Permanently delete a staged draft from Drafts. |

### Message tags / labels (IMAP keywords)

Custom tags are stored as IMAP keywords on the message (not as folders). Flow:

1. `email_list_labels` — discover keywords already used in a folder (optional).
2. `email_get_message_labels` — read current flags + tags for one UID.
3. `email_update_message_flags` with `add_labels` / `remove_labels` — write tags.

Keyword names cannot contain spaces. Standard flags (`seen`, `flagged` / `starred`, `answered`) use the boolean arguments on `email_update_message_flags`.

## 3. Sending mail with `email_send`

The `email_send` tool is intentionally close to "what a human in Thunderbird would do":

- **Body is markdown.** It's rendered as both a plain-text alternative (the markdown is already legible) and a clean HTML version with sane default styling.
- **Replies are real replies.** When you pass `reply_to`, Prism fetches the original from IMAP, adds proper `In-Reply-To` and `References` headers (so the recipient's mail client groups the conversation), prefixes the subject with `Re:` if needed, and quotes the original message at the bottom of the body.
- **A copy is saved to Sent.** By default the same MIME bytes that get sent over SMTP are appended to the IMAP `Sent` folder with the `\Seen` flag, so the conversation looks identical from the user's mail client.

### Sending a brand-new message

```json
{
  "account": "personal-mail",
  "to": "alice@example.com",
  "cc": ["bob@example.com"],
  "subject": "Lunch tomorrow?",
  "body_markdown": "Hey Alice,\n\nWant to grab **lunch** tomorrow at noon?\n\nCheers"
}
```

### Replying to an existing message

First find the message you want to reply to (`email_search` → `email_get_messages`), then pass its folder + UID:

```json
{
  "account": "personal-mail",
  "body_markdown": "Sounds great — see you at the cafe at 12:30!",
  "reply_to": {
    "folder": "INBOX",
    "uid": 4821
  }
}
```

When `reply_to` is supplied and you omit `to`/`cc`, recipients are derived from the original message:

- Default: `to` = original sender (or `Reply-To` if the original specified one).
- `reply_to.reply_all = true`: `to` = original sender, `cc` = original `To` + `Cc` minus your own address.

You can always override by passing explicit `to`/`cc`/`bcc`.

### Useful options

| Field | Description |
|-------|-------------|
| `from_name` | Override the From display name for this single message. |
| `reply_to_address` | Add a `Reply-To` header so replies go elsewhere. |
| `save_to_sent` | Default `true`. Set `false` for fire-and-forget sends. |
| `sent_folder` | Override the IMAP folder used for the saved copy (default = account's `sent_folder`, falling back to `"Sent"`). |

### What you get back

`email_send` returns the new message's `Message-ID`, the resolved subject, the recipient lists actually used, the original message-id it replied to (if any), and whether the Sent copy was saved successfully. If sending succeeded but appending to Sent failed, you get a `warning` field rather than an error — the recipient already has the message, after all.

## 4. Staging drafts

Use drafts when a human should review (and send) from their own mail client — Thunderbird, Apple Mail, webmail, etc. Nothing goes out over SMTP.

### Create a draft

`email_create_draft` composes the same multipart message as `email_send` (markdown → text + HTML) and `APPEND`s it to the account's Drafts folder with flags `\Seen \Draft`. Optional `reply_to: { folder, uid }` threads and quotes the original exactly like a real reply draft. Bcc is preserved in the draft so the client can restore it when the user opens the composer.

```json
{
  "account": "personal-mail",
  "body_markdown": "Thanks — I'll follow up tomorrow.",
  "reply_to": {
    "folder": "INBOX",
    "uid": 4821
  }
}
```

SMTP is **not** required. Recipients may be omitted (fill them in later in the mail client); on replies without `to`/`cc`, they are derived from the original.

The response includes `folder`, `uid` (when resolvable), and `message_id`. If `uid` is null, locate the draft with `email_search` on the Drafts folder.

### Replace or discard a draft

There is no upsert. To replace: `email_delete_draft` (by `uid`, optionally with `expected_message_id` as a guard), then `email_create_draft` again. Deletion is permanent (expunge) and only allowed for messages that have the `\Draft` flag.

### Reading drafts

Drafts are ordinary IMAP messages. Use `email_search` / `email_get_messages` on the Drafts folder (see `drafts_folder` from `email_list_accounts`, or override with `drafts_folder` on the draft tools). Confirm the folder name with `email_list_folders` if needed (`Drafts`, `INBOX.Drafts`, `[Gmail]/Drafts`, …).

### Soft-deleted messages (`\\Deleted`) vs mail-client UI

IMAP delete is two-step: the client marks a message `\\Deleted`, then later runs `EXPUNGE` to remove it. Thunderbird (and similar clients) typically **hide** soft-deleted messages immediately, while other IMAP clients still see them until expunge (often on compact, folder close, or restart).

Prism matches that UI default:

- `email_search` / `email_multi_search` append IMAP `UNDELETED` unless you pass `include_deleted: true`.
- Message summaries and full fetches expose a `deleted` boolean so soft-deleted messages are recognizable when you opt in.

Typical case: a draft staged with `email_create_draft`, then sent from Thunderbird — TB marks the draft deleted (gone from the TB Drafts list) but Prism without `UNDELETED` used to still list it until EXPUNGE. With the default filter, both views stay aligned.

## 5. Approval workflow

Sending mail on behalf of a user is high-impact: AI clients are expected to flag `email_send` as **requires approval** so the request is queued, reviewed, and only then executed. Prism itself does not enforce approval — that's the responsibility of the AI client. Configure your client (e.g. Cursor's tool approval policy) to gate `email_send`.

Staging a draft (`email_create_draft`) is intentionally lower risk — nothing is sent. Deleting a draft is destructive but narrowly scoped to the Drafts folder.

## 6. Typical workflow

1. `email_list_accounts` → find the account key.
2. `email_search` → locate the conversation you want to act on.
3. `email_get_messages` → read the full message (set `include_html: true` if you want both versions; pass one UID if you only need one message).
4. Either:
   - `email_send` with `reply_to: { folder, uid }` → reply, threaded and saved, just like a human would; or
   - `email_create_draft` with `reply_to` → stage for human review/send in their mail client.

## 7. Provider quick reference

These are the most common settings; check your provider's docs to be sure.

| Provider | IMAP host | IMAP port/enc | SMTP host | SMTP port/enc |
|----------|-----------|---------------|-----------|---------------|
| Generic / self-hosted (Dovecot+Postfix) | `mail.example.com` | 993 / ssl | `mail.example.com` | 465 / ssl (or 587 / starttls) |
| Rackspace Email | `secure.emailsrvr.com` | 993 / ssl | `secure.emailsrvr.com` | 465 / ssl |
| Gmail (with app password or OAuth XOAUTH2) | `imap.gmail.com` | 993 / ssl | `smtp.gmail.com` | 465 / ssl |
| Outlook 365 (basic auth where allowed) | `outlook.office365.com` | 993 / ssl | `smtp.office365.com` | 587 / starttls |
| Fastmail | `imap.fastmail.com` | 993 / ssl | `smtp.fastmail.com` | 465 / ssl |

> **Use app-specific passwords.** Most providers require an app-specific password (or modern OAuth) when a third-party client logs in. Generate one in your provider's account settings and put it in the config.

## 8. Troubleshooting

**"Failed to connect to email account … (IMAP)"** — Check host/port/encryption. For self-signed certs in dev, set `validate_cert: false`.

**"Email account … has no SMTP configured"** — `email_send` requires a `smtp:` block on the account.

**Sent message has no copy in Sent folder** — Confirm the folder name. Some providers use `Sent`, others use `Sent Items`, `Sent Messages`, or `INBOX.Sent`. Use `email_list_folders` to find the exact name and set `imap.sent_folder` (or pass `sent_folder` per-call). If the folder doesn't exist, Prism will try to create it once.

**Reply isn't threading correctly in the recipient's client** — Mail clients group threads by `Message-ID`/`In-Reply-To`/`References`. Make sure the `reply_to.uid` you pass is the original message's UID in the folder you specify, not a derived value.

**Draft still listed after sending from Thunderbird** — TB usually soft-deletes (`\\Deleted`) the draft and hides it; EXPUNGE may wait until compact/restart. Prism search excludes soft-deleted messages by default. Pass `include_deleted: true` only when diagnosing leftovers. Confirm the sent copy in the account's Sent folder (`Sent` vs `Sent Items`).

**"Authentication failed"** — Some providers reject normal passwords on SMTP/IMAP and require an app-specific password (or OAuth). Generate one in your provider's account security settings.
