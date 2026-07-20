# Prism — Future MCP Tools Roadmap

## Email Bridge (IMAP + SMTP)
- [x] Multi-profile email configuration (`type: email` profiles in `prism.config.yaml`)
- [x] `email_list_profiles` — List configured profiles
- [x] `email_list_folders` — List all mailbox folders for a profile
- [x] `email_create_folder` — Create an IMAP folder (error if it already exists)
- [x] `email_search` — Search emails by subject, sender, date range, flags
- [x] `email_get_messages` — Fetch full email content in bulk (including single-message fetches)
- [x] `email_send` — Send a new message or threaded reply (markdown body, save-to-Sent)
- [x] `email_move_message` — Move message between folders
- [x] `email_update_message_flags` — Set/unset flags and custom keyword tags
- [x] `email_get_message_labels` — Read flags and custom keyword tags on a message
- [ ] Attachment support on `email_send`
- [ ] Drafts: `email_save_draft`

## bunq Banking Bridge
- [ ] bunq API authentication (API key + permitted IPs config)
- [ ] `bunq_list_profiles` — List all monetary accounts
- [ ] `bunq_list_transactions` — Pull transactions with date/amount filters
- [ ] `bunq_get_balance` — Get current profile balance
- [ ] `bunq_transaction_details` — Get details of a specific transaction
- [ ] `bunq_list_cards` — List linked cards and their status

## Cyans API Bridge
- [ ] Cyans API authentication setup
- [ ] `cyans_pull_data` — Generic data pull from Cyans API
- [ ] `cyans_list_resources` — List available resources/endpoints
- [ ] `cyans_get_resource` — Fetch a specific resource by ID

## Picnic Bridge
- [x] Picnic API authentication (username/password, cached `x-picnic-auth` token)
- [x] `picnic_search_products` — Search grocery products
- [x] `picnic_get_cart` / `picnic_add_to_cart` / `picnic_remove_from_cart` — Shopping cart management
- [x] `picnic_list_deliveries` / `picnic_get_delivery` — Past and upcoming deliveries
- [ ] `picnic_get_delivery_position` — Live tracking position for a delivery
- [ ] `picnic_list_slots` — List available delivery slots
- [ ] `picnic_set_slot` — Reserve a delivery slot

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
