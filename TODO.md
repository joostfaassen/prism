# Joost Bridge — Future MCP Tools Roadmap

## IMAP Mail Bridge
- [ ] Multi-account IMAP configuration (named accounts in `.env.local`)
- [ ] `imap_list_folders` — List all mailbox folders for a named account
- [ ] `imap_search` — Search emails by subject, sender, date range, flags
- [ ] `imap_fetch_message` — Fetch full email content (headers, body, attachments metadata)
- [ ] `imap_list_recent` — List recent/unread messages in a folder
- [ ] `imap_move_message` — Move message between folders
- [ ] `imap_flag_message` — Set/unset flags (read, starred, etc.)

## bunq Banking Bridge
- [ ] bunq API authentication (API key + permitted IPs config)
- [ ] `bunq_list_accounts` — List all monetary accounts
- [ ] `bunq_list_transactions` — Pull transactions with date/amount filters
- [ ] `bunq_get_balance` — Get current account balance
- [ ] `bunq_transaction_details` — Get details of a specific transaction
- [ ] `bunq_list_cards` — List linked cards and their status

## Cyans API Bridge
- [ ] Cyans API authentication setup
- [ ] `cyans_pull_data` — Generic data pull from Cyans API
- [ ] `cyans_list_resources` — List available resources/endpoints
- [ ] `cyans_get_resource` — Fetch a specific resource by ID

## Custom API Bridges (Generic)
- [ ] Generic REST API bridge pattern (configurable base URL, auth, endpoints)
- [ ] `api_get` — Generic GET request to configured API
- [ ] `api_post` — Generic POST request with JSON body
- [ ] `api_list_endpoints` — List configured API endpoints

## Infrastructure Tools
- [ ] `dns_lookup` — DNS record lookup for a domain
- [ ] `http_check` — Check HTTP status/response of a URL
- [ ] `ssl_check` — Check SSL certificate details and expiry

## Utility Tools
- [ ] `json_format` — Pretty-print / validate JSON
- [ ] `base64_encode` / `base64_decode` — Encode/decode base64 strings
- [ ] `uuid_generate` — Generate UUIDs (v4, v7)
- [ ] `hash` — Generate hash (md5, sha256, etc.) of input text
- [ ] `timestamp_convert` — Convert between unix timestamps and human-readable dates
