<?php

namespace App\Libredesk;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class LibredeskService
{
    public function __construct(
        private readonly LibredeskConfigLoader $configLoader,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function listAccounts(): array
    {
        $accounts = [];
        foreach ($this->configLoader->getAccounts() as $key => $account) {
            $accounts[] = [
                'key' => $key,
                'label' => $account->label,
            ];
        }

        return $accounts;
    }

    /**
     * List conversations for a given view.
     *
     * @param string $view One of: all, unassigned, team_unassigned, assigned
     *
     * @return array<string, mixed>
     */
    public function listConversations(
        string $accountKey,
        string $view = 'all',
        int $page = 1,
        int $pageSize = 30,
        ?int $inboxId = null,
        ?int $teamId = null,
        ?string $status = null,
        ?string $orderBy = null,
        ?string $order = null,
    ): array {
        $endpoint = match ($view) {
            'unassigned' => 'conversations/unassigned',
            'team_unassigned' => 'conversations/team/unassigned',
            'assigned' => 'conversations/assigned',
            default => 'conversations/all',
        };

        $query = [
            'page' => $page,
            'page_size' => min($pageSize, 100),
        ];

        // Build Libredesk server-side filters (JSON array of {model, field,
        // operator, value}). Allowed conversation fields include inbox_id and
        // assigned_team_id; status is matched by name via conversation_statuses.
        $filters = [];
        if ($inboxId !== null) {
            $filters[] = ['model' => 'conversations', 'field' => 'inbox_id', 'operator' => 'equals', 'value' => (string) $inboxId];
        }
        if ($teamId !== null) {
            $filters[] = ['model' => 'conversations', 'field' => 'assigned_team_id', 'operator' => 'equals', 'value' => (string) $teamId];
        }
        if ($status !== null && $status !== '') {
            $filters[] = ['model' => 'conversation_statuses', 'field' => 'name', 'operator' => 'equals', 'value' => $status];
        }
        if ($filters !== []) {
            $query['filters'] = json_encode($filters, JSON_THROW_ON_ERROR);
        }

        // Ordering. Accept a bare conversation column (e.g. "created_at") and
        // qualify it with the conversations model as Libredesk expects.
        if ($orderBy !== null && $orderBy !== '') {
            $allowedOrderFields = [
                'created_at', 'last_message_at', 'last_interaction_at',
                'waiting_since', 'next_sla_deadline_at', 'priority_id', 'status_id',
            ];
            $orderField = str_contains($orderBy, '.') ? explode('.', $orderBy)[1] : $orderBy;
            if (in_array($orderField, $allowedOrderFields, true)) {
                $query['order_by'] = 'conversations.' . $orderField;
                $orderDir = strtolower((string) $order);
                $query['order'] = $orderDir === 'asc' ? 'asc' : 'desc';
            }
        }

        $data = $this->request($accountKey, 'GET', $endpoint, $query);

        $payload = $data['data'] ?? [];
        $conversations = [];
        foreach ($payload['results'] ?? [] as $conv) {
            $conversations[] = $this->simplifyListItem($conv);
        }

        return [
            'conversations' => $conversations,
            'page' => $payload['page'] ?? $page,
            'per_page' => $payload['per_page'] ?? $pageSize,
            'total' => $payload['total'] ?? null,
            'total_pages' => $payload['total_pages'] ?? null,
        ];
    }

    /**
     * Search conversations by free-text query (minimum 3 characters).
     *
     * @return list<array<string, mixed>>
     */
    public function searchConversations(string $accountKey, string $query): array
    {
        $data = $this->request($accountKey, 'GET', 'conversations/search', ['query' => $query]);

        $results = [];
        foreach ($data['data'] ?? [] as $conv) {
            $results[] = [
                'uuid' => $conv['uuid'] ?? null,
                'reference_number' => $conv['reference_number'] ?? null,
                'subject' => $conv['subject'] ?? '',
                'created_at' => $conv['created_at'] ?? null,
            ];
        }

        return $results;
    }

    /**
     * Get the raw conversation record (without messages).
     *
     * @return array<string, mixed>
     */
    public function getConversationRaw(string $accountKey, string $uuid): array
    {
        $data = $this->request($accountKey, 'GET', "conversations/{$uuid}");

        return $data['data'] ?? [];
    }

    /**
     * Get the messages of a conversation.
     *
     * @return list<array<string, mixed>>
     */
    public function getMessages(string $accountKey, string $uuid, bool $includeNotes = true): array
    {
        $allMessages = [];
        $page = 1;

        do {
            $query = ['page' => $page, 'page_size' => 100];
            $data = $this->request($accountKey, 'GET', "conversations/{$uuid}/messages", $query);
            $payload = $data['data'] ?? [];

            foreach ($payload['results'] ?? [] as $message) {
                if (!$includeNotes && ($message['private'] ?? false)) {
                    continue;
                }
                $allMessages[] = $message;
            }

            $totalPages = $payload['total_pages'] ?? 1;
            $page++;
        } while ($page <= $totalPages && $page <= 50);

        return $allMessages;
    }

    /**
     * Get conversation in simplified JSON format (key fields + plain-text messages).
     *
     * @return array<string, mixed>
     */
    public function getConversationSimple(string $accountKey, string $uuid, bool $includeNotes = false): array
    {
        $conv = $this->getConversationRaw($accountKey, $uuid);
        $messages = $this->getMessages($accountKey, $uuid, $includeNotes);

        $simplified = [
            'uuid' => $conv['uuid'] ?? $uuid,
            'reference_number' => $conv['reference_number'] ?? null,
            'subject' => $conv['subject'] ?? null,
            'status' => $conv['status'] ?? null,
            'priority' => $conv['priority'] ?? null,
            'inbox_name' => $conv['inbox_name'] ?? null,
            'inbox_channel' => $conv['inbox_channel'] ?? null,
            'created_at' => $conv['created_at'] ?? null,
            'updated_at' => $conv['updated_at'] ?? null,
            'resolved_at' => $conv['resolved_at'] ?? null,
            'closed_at' => $conv['closed_at'] ?? null,
            'contact' => [
                'id' => $conv['contact']['id'] ?? null,
                'email' => $conv['contact']['email'] ?? null,
                'first_name' => $conv['contact']['first_name'] ?? null,
                'last_name' => $conv['contact']['last_name'] ?? null,
            ],
            'tags' => $conv['tags'] ?? [],
            'messages' => [],
        ];

        foreach ($messages as $message) {
            $simplified['messages'][] = [
                'uuid' => $message['uuid'] ?? null,
                'type' => $message['type'] ?? null,
                'sender_type' => $message['sender_type'] ?? null,
                'private' => (bool) ($message['private'] ?? false),
                'created_at' => $message['created_at'] ?? null,
                'body' => $this->messageText($message),
            ];
        }

        return $simplified;
    }

    /**
     * Get conversation as plain text optimized for AI consumption.
     */
    public function getConversationText(string $accountKey, string $uuid, bool $includeNotes = false): string
    {
        $conv = $this->getConversationRaw($accountKey, $uuid);
        $messages = $this->getMessages($accountKey, $uuid, $includeNotes);

        $ref = $conv['reference_number'] ?? ($conv['uuid'] ?? $uuid);

        $output = '';
        $output .= "======= CONVERSATION #{$ref} =======\n";
        $output .= "Subject: " . ($conv['subject'] ?? 'N/A') . "\n";
        $output .= "Status: " . ($conv['status'] ?? 'N/A') . "\n";
        $output .= "Priority: " . ($conv['priority'] ?? 'N/A') . "\n";
        $output .= "Created: " . ($conv['created_at'] ?? 'N/A') . "\n";
        $output .= "Updated: " . ($conv['updated_at'] ?? 'N/A') . "\n";
        $output .= "Contact: " . $this->contactLabel($conv['contact'] ?? []) . "\n";

        if (!empty($conv['inbox_name'])) {
            $output .= "Inbox: " . $conv['inbox_name'] . "\n";
        }

        $tags = $conv['tags'] ?? [];
        if (!empty($tags)) {
            $output .= "Tags: " . implode(', ', $tags) . "\n";
        }

        $output .= "\n";

        foreach ($messages as $message) {
            $isPrivate = (bool) ($message['private'] ?? false);

            $output .= "--- MESSAGE ---\n";
            $output .= "From: " . $this->messageFrom($message) . "\n";
            $output .= "Date: " . ($message['created_at'] ?? 'Unknown') . "\n";
            $output .= "Type: " . ($message['type'] ?? 'unknown') . ($isPrivate ? ' (private note)' : '') . "\n";

            $to = $message['meta']['to'] ?? [];
            if (!empty($to)) {
                $output .= "To: " . implode(', ', $to) . "\n";
            }

            $output .= "\n";
            $output .= $this->messageText($message);
            $output .= "\n\n";
        }

        return $output;
    }

    /**
     * Get conversation in structured convo format (participants + typed messages).
     *
     * @return array<string, mixed>
     */
    public function getConversationConvo(string $accountKey, string $uuid, bool $includeNotes = false): array
    {
        $conv = $this->getConversationRaw($accountKey, $uuid);
        $messages = $this->getMessages($accountKey, $uuid, $includeNotes);

        $statusMap = [
            'Open' => 'open',
            'Resolved' => 'closed',
            'Closed' => 'closed',
            'Snoozed' => 'pending',
        ];

        $status = $conv['status'] ?? '';

        $convo = [
            'id' => (string) ($conv['uuid'] ?? $uuid),
            'subject' => $conv['subject'] ?? '',
            'status' => $statusMap[$status] ?? ($status !== '' ? strtolower((string) $status) : null),
            'channel' => $conv['inbox_channel'] ?? 'email',
            'created_at' => $conv['created_at'] ?? null,
            'updated_at' => $conv['updated_at'] ?? null,
            'closed_at' => $conv['closed_at'] ?? null,
        ];

        $metadata = [];
        if (!empty($conv['inbox_name'])) {
            $metadata['inbox'] = $conv['inbox_name'];
        }
        if (!empty($conv['reference_number'])) {
            $metadata['reference_number'] = $conv['reference_number'];
        }
        if (!empty($conv['priority'])) {
            $metadata['priority'] = $conv['priority'];
        }
        if (!empty($conv['tags'])) {
            $metadata['tags'] = $conv['tags'];
        }
        if (!empty($metadata)) {
            $convo['metadata'] = $metadata;
        }

        $participantsById = [];

        $contactEmail = $conv['contact']['email'] ?? null;
        if ($contactEmail) {
            $participantsById[$contactEmail] = [
                'id' => $contactEmail,
                'name' => $this->contactLabel($conv['contact'] ?? []),
                'role' => 'customer',
                'email' => $contactEmail,
            ];
        }

        foreach ($messages as $message) {
            $from = $message['meta']['from'][0] ?? null;
            if ($from && !isset($participantsById[$from])) {
                $participantsById[$from] = [
                    'id' => $from,
                    'name' => $from,
                    'role' => ($message['sender_type'] ?? '') === 'contact' ? 'customer' : 'agent',
                    'email' => $from,
                ];
            }
        }

        $convo['participants'] = array_values($participantsById);

        $convoMessages = [];
        foreach ($messages as $message) {
            $isPrivate = (bool) ($message['private'] ?? false);
            $convoMessages[] = [
                'participant' => $message['meta']['from'][0]
                    ?? (($message['sender_type'] ?? '') === 'contact' ? $contactEmail : null),
                'type' => $isPrivate ? 'internal' : 'message',
                'created_at' => $message['created_at'] ?? null,
                'message' => $this->messageText($message),
            ];
        }

        $convo['messages'] = $convoMessages;

        return $convo;
    }

    /**
     * @return array<string, mixed>
     */
    public function listAgents(string $accountKey): array
    {
        $data = $this->request($accountKey, 'GET', 'agents');

        $agents = [];
        foreach ($this->flattenData($data) as $agent) {
            $agents[] = [
                'id' => $agent['id'] ?? null,
                'email' => $agent['email'] ?? null,
                'first_name' => $agent['first_name'] ?? null,
                'last_name' => $agent['last_name'] ?? null,
                'enabled' => $agent['enabled'] ?? null,
            ];
        }

        return ['count' => count($agents), 'agents' => $agents];
    }

    /**
     * @return array<string, mixed>
     */
    public function listTeams(string $accountKey): array
    {
        $data = $this->request($accountKey, 'GET', 'teams');

        $teams = [];
        foreach ($this->flattenData($data) as $team) {
            $teams[] = [
                'id' => $team['id'] ?? null,
                'name' => $team['name'] ?? null,
                'emoji' => $team['emoji'] ?? null,
                'timezone' => $team['timezone'] ?? null,
            ];
        }

        return ['count' => count($teams), 'teams' => $teams];
    }

    /**
     * List all macros configured in the Libredesk instance.
     *
     * Requires only an authenticated API key (any enabled agent). Returns the
     * macro id, name, reply template, configured actions, and visibility.
     *
     * @return array<string, mixed>
     */
    public function listMacros(string $accountKey): array
    {
        $data = $this->request($accountKey, 'GET', 'macros');

        $macros = [];
        foreach ($this->flattenData($data) as $macro) {
            $macros[] = [
                'id' => $macro['id'] ?? null,
                'name' => $macro['name'] ?? null,
                'message_content' => $macro['message_content'] ?? null,
                'actions' => $macro['actions'] ?? [],
                'visibility' => $macro['visibility'] ?? null,
                'visible_when' => $macro['visible_when'] ?? [],
                'user_id' => $macro['user_id'] ?? null,
                'team_id' => $macro['team_id'] ?? null,
                'usage_count' => $macro['usage_count'] ?? null,
            ];
        }

        return ['count' => count($macros), 'macros' => $macros];
    }

    /**
     * Send a message (reply to contact or internal note) on a conversation.
     *
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<string> $bcc
     *
     * @return array<string, mixed>
     */
    public function sendMessage(
        string $accountKey,
        string $uuid,
        string $message,
        bool $private = false,
        string $senderType = 'agent',
        array $to = [],
        array $cc = [],
        array $bcc = [],
    ): array {
        $body = [
            'message' => $message,
            'sender_type' => $senderType,
            'private' => $private,
        ];

        if (!empty($to)) {
            $body['to'] = $to;
        }
        if (!empty($cc)) {
            $body['cc'] = $cc;
        }
        if (!empty($bcc)) {
            $body['bcc'] = $bcc;
        }

        $data = $this->request($accountKey, 'POST', "conversations/{$uuid}/messages", [], $body);

        return $data['data'] ?? $data;
    }

    /**
     * Create or update the per-agent draft reply for a conversation.
     *
     * The draft is stored against the agent that owns the configured API key
     * and is NEVER sent automatically — it only pre-fills that agent's reply
     * editor when they open the conversation in Libredesk.
     *
     * @param array<string, mixed>|null $meta Optional metadata (attachments, macro actions); max ~32KB.
     *
     * @return array<string, mixed>
     */
    public function upsertDraft(
        string $accountKey,
        string $uuid,
        string $content,
        ?array $meta = null,
    ): array {
        // Libredesk's conversation_drafts.meta column is JSONB NOT NULL, so we
        // must always send a meta object. Omitting it makes Libredesk insert
        // SQL NULL, which violates the NOT NULL constraint and returns HTTP 500.
        $body = [
            'content' => $content,
            'meta' => $meta ?? (object) [],
        ];

        $data = $this->request($accountKey, 'POST', "conversations/{$uuid}/draft", [], $body);

        return $data['data'] ?? $data;
    }

    /**
     * Get the existing draft reply for a conversation (for the API key's agent).
     *
     * @return array<string, mixed>
     */
    public function getDraft(string $accountKey, string $uuid): array
    {
        // Libredesk has no per-conversation GET draft route; it only exposes
        // GET /api/v1/drafts (all drafts for the API key's agent). We fetch
        // those and filter by conversation UUID.
        $data = $this->request($accountKey, 'GET', 'drafts');
        $drafts = $data['data'] ?? $data;

        if (!is_array($drafts)) {
            return [];
        }

        foreach ($drafts as $draft) {
            if (is_array($draft) && ($draft['conversation_uuid'] ?? null) === $uuid) {
                return $draft;
            }
        }

        return [];
    }

    /**
     * Delete the draft reply for a conversation (for the API key's agent).
     *
     * @return array<string, mixed>
     */
    public function deleteDraft(string $accountKey, string $uuid): array
    {
        return $this->request($accountKey, 'DELETE', "conversations/{$uuid}/draft");
    }

    /**
     * Update the status of a conversation.
     *
     * @return array<string, mixed>
     */
    public function updateStatus(
        string $accountKey,
        string $uuid,
        string $status,
        ?string $snoozedUntil = null,
    ): array {
        $body = ['status' => $status];

        if ($snoozedUntil !== null) {
            $body['snoozed_until'] = $snoozedUntil;
        }

        return $this->request($accountKey, 'PUT', "conversations/{$uuid}/status", [], $body);
    }

    /**
     * @param array<string, mixed> $conv
     *
     * @return array<string, mixed>
     */
    private function simplifyListItem(array $conv): array
    {
        return [
            'uuid' => $conv['uuid'] ?? null,
            'subject' => $conv['subject'] ?? '',
            'status' => $conv['status'] ?? null,
            'priority' => $conv['priority'] ?? null,
            'inbox_name' => $conv['inbox_name'] ?? null,
            'inbox_channel' => $conv['inbox_channel'] ?? null,
            'unread_message_count' => $conv['unread_message_count'] ?? null,
            'last_message' => $conv['last_message'] ?? null,
            'last_message_at' => $conv['last_message_at'] ?? null,
            'last_message_sender' => $conv['last_message_sender'] ?? null,
            'waiting_since' => $conv['waiting_since'] ?? null,
            'contact' => [
                'first_name' => $conv['contact']['first_name'] ?? null,
                'last_name' => $conv['contact']['last_name'] ?? null,
            ],
            'created_at' => $conv['created_at'] ?? null,
            'updated_at' => $conv['updated_at'] ?? null,
        ];
    }

    /**
     * Some endpoints wrap the payload as { status, data: [...] } while a few
     * double-wrap as { data: { data: [...] } }. Normalize to a flat list.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function flattenData(array $data): array
    {
        $payload = $data['data'] ?? [];

        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }

        if (!is_array($payload)) {
            return [];
        }

        return array_values(array_filter($payload, 'is_array'));
    }

    /**
     * @param array<string, mixed> $message
     */
    private function messageText(array $message): string
    {
        $text = $message['text_content'] ?? '';
        if (is_string($text) && trim($text) !== '') {
            return trim($text);
        }

        return $this->htmlToPlainText((string) ($message['content'] ?? ''));
    }

    /**
     * @param array<string, mixed> $message
     */
    private function messageFrom(array $message): string
    {
        $from = $message['meta']['from'][0] ?? null;
        if (is_string($from) && $from !== '') {
            return $from;
        }

        return ucfirst((string) ($message['sender_type'] ?? 'unknown'));
    }

    /**
     * @param array<string, mixed> $contact
     */
    private function contactLabel(array $contact): string
    {
        $name = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''));
        $email = $contact['email'] ?? '';

        if ($name !== '' && $email !== '') {
            return "{$name} <{$email}>";
        }

        return $name !== '' ? $name : ($email !== '' ? $email : 'N/A');
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $json
     *
     * @return array<string, mixed>
     */
    private function request(
        string $accountKey,
        string $method,
        string $endpoint,
        array $query = [],
        ?array $json = null,
    ): array {
        $account = $this->configLoader->getAccount($accountKey);

        if ($account->baseUrl === '' || $account->apiKey === '' || $account->apiSecret === '') {
            throw new \RuntimeException(sprintf(
                'Libredesk account "%s" is missing base_url, api_key, or api_secret',
                $accountKey,
            ));
        }

        $url = $account->baseUrl . '/api/v1/' . ltrim($endpoint, '/');

        $options = [
            'headers' => [
                'Authorization' => sprintf('token %s:%s', $account->apiKey, $account->apiSecret),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ];

        if (!empty($query)) {
            $options['query'] = $query;
        }
        if ($json !== null) {
            $options['json'] = $json;
        }

        $response = $this->httpClient->request($method, $url, $options);

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            $body = $response->getContent(false);
            throw new \RuntimeException(sprintf(
                'Libredesk API error (HTTP %d): %s',
                $statusCode,
                $body,
            ));
        }

        $content = $response->getContent();
        if ($content === '') {
            return [];
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    private function htmlToPlainText(string $html): string
    {
        $search = [
            '@<br\s*/?>@i',
            '@</p>@i',
            '@</div>@i',
        ];
        $replace = ["\n", "\n\n", "\n"];
        $text = preg_replace($search, $replace, $html);

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}
