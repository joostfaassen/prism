<?php

namespace App\Freescout;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class FreescoutService
{
    /** @var array<string, HttpClientInterface> */
    private array $clients = [];

    public function __construct(
        private readonly FreescoutConfigLoader $configLoader,
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
     * @return list<array<string, mixed>>
     */
    public function listMailboxes(string $accountKey): array
    {
        $response = $this->request($accountKey, 'GET', 'mailboxes');
        $data = $response;

        $mailboxes = [];
        foreach ($data['_embedded']['mailboxes'] ?? [] as $mailbox) {
            $mailboxes[] = [
                'id' => $mailbox['id'],
                'name' => $mailbox['name'],
                'email' => $mailbox['email'] ?? null,
            ];
        }

        return $mailboxes;
    }

    /**
     * @return array<string, mixed>
     */
    public function listConversations(
        string $accountKey,
        ?int $mailboxId = null,
        ?int $folderId = null,
        ?string $status = null,
        ?string $updatedSince = null,
        int $page = 1,
        int $pageSize = 50,
    ): array {
        $params = ['page' => $page, 'pageSize' => $pageSize];

        if ($mailboxId !== null) {
            $params['mailboxId'] = $mailboxId;
        }
        if ($folderId !== null) {
            $params['folderId'] = $folderId;
        }
        if ($status !== null) {
            $params['status'] = $status;
        }
        if ($updatedSince !== null) {
            $params['updatedSince'] = $updatedSince;
        }

        $data = $this->request($accountKey, 'GET', 'conversations', $params);

        $conversations = [];
        foreach ($data['_embedded']['conversations'] ?? [] as $conv) {
            $conversations[] = [
                'id' => $conv['id'],
                'number' => $conv['number'] ?? null,
                'subject' => $conv['subject'] ?? '',
                'status' => $conv['status'] ?? null,
                'state' => $conv['state'] ?? null,
                'type' => $conv['type'] ?? null,
                'preview' => $conv['preview'] ?? null,
                'mailbox_id' => $conv['mailboxId'] ?? null,
                'assignee' => isset($conv['assignee']) ? [
                    'id' => $conv['assignee']['id'] ?? null,
                    'email' => $conv['assignee']['email'] ?? null,
                    'firstName' => $conv['assignee']['firstName'] ?? null,
                    'lastName' => $conv['assignee']['lastName'] ?? null,
                ] : null,
                'customer' => isset($conv['customer']) ? [
                    'id' => $conv['customer']['id'] ?? null,
                    'email' => $conv['customer']['email'] ?? null,
                    'firstName' => $conv['customer']['firstName'] ?? null,
                    'lastName' => $conv['customer']['lastName'] ?? null,
                ] : null,
                'threads_count' => $conv['threadsCount'] ?? null,
                'created_at' => $conv['createdAt'] ?? null,
                'updated_at' => $conv['updatedAt'] ?? null,
            ];
        }

        return [
            'conversations' => $conversations,
            'page' => $data['page'] ?? [],
        ];
    }

    /**
     * Get full conversation data from the API.
     *
     * @return array<string, mixed>
     */
    public function getConversationRaw(string $accountKey, int $conversationId): array
    {
        return $this->request($accountKey, 'GET', "conversations/{$conversationId}", [
            'embed' => 'tags,threads',
        ]);
    }

    /**
     * Get conversation in simplified JSON format.
     *
     * @return array<string, mixed>
     */
    public function getConversationSimple(string $accountKey, int $conversationId): array
    {
        $data = $this->getConversationRaw($accountKey, $conversationId);

        $simplified = [
            'id' => $data['id'] ?? null,
            'subject' => $data['subject'] ?? null,
            'preview' => $data['preview'] ?? null,
            'status' => $data['status'] ?? null,
            'state' => $data['state'] ?? null,
            'type' => $data['type'] ?? null,
            'created_at' => $data['createdAt'] ?? null,
            'updated_at' => $data['updatedAt'] ?? null,
            'customer' => [
                'id' => $data['customer']['id'] ?? null,
                'email' => $data['customer']['email'] ?? null,
                'firstName' => $data['customer']['firstName'] ?? null,
                'lastName' => $data['customer']['lastName'] ?? null,
            ],
            'assignee' => [
                'id' => $data['assignee']['id'] ?? null,
                'email' => $data['assignee']['email'] ?? null,
                'firstName' => $data['assignee']['firstName'] ?? null,
                'lastName' => $data['assignee']['lastName'] ?? null,
            ],
            'mailbox' => [
                'id' => $data['mailbox']['id'] ?? null,
                'name' => $data['mailbox']['name'] ?? null,
                'email' => $data['mailbox']['email'] ?? null,
            ],
            'custom_fields' => [],
            'tags' => [],
            'threads' => [],
        ];

        foreach ($data['customFields'] ?? [] as $field) {
            $simplified['custom_fields'][$field['name'] ?? 'unknown'] = $field['value'] ?? null;
        }

        foreach ($data['_embedded']['tags'] ?? [] as $tag) {
            $simplified['tags'][] = $tag['name'] ?? '';
        }

        foreach ($data['_embedded']['threads'] ?? [] as $thread) {
            $simplified['threads'][] = [
                'id' => $thread['id'] ?? null,
                'type' => $thread['type'] ?? null,
                'state' => $thread['state'] ?? null,
                'created_at' => $thread['createdAt'] ?? null,
                'body' => $this->htmlToPlainText($thread['body'] ?? ''),
                'created_by' => [
                    'email' => $thread['createdBy']['email'] ?? null,
                    'firstName' => $thread['createdBy']['firstName'] ?? null,
                    'lastName' => $thread['createdBy']['lastName'] ?? null,
                ],
            ];
        }

        return $simplified;
    }

    /**
     * Get conversation as plain text optimized for AI consumption.
     */
    public function getConversationText(string $accountKey, int $conversationId, bool $includeNotes = false): string
    {
        $data = $this->getConversationRaw($accountKey, $conversationId);

        $output = '';
        $output .= "======= CONVERSATION #{$conversationId} =======\n";
        $output .= "Subject: " . ($data['subject'] ?? 'N/A') . "\n";
        $output .= "Status: " . ($data['status'] ?? 'N/A') . "\n";
        $output .= "Created: " . ($data['createdAt'] ?? 'N/A') . "\n";
        $output .= "Updated: " . ($data['updatedAt'] ?? 'N/A') . "\n";
        $output .= "Customer: " . ($data['customer']['email'] ?? 'N/A') . "\n";
        $output .= "Assignee: " . ($data['assignee']['email'] ?? 'N/A') . "\n";

        if (!empty($data['mailbox']['name'])) {
            $output .= "Mailbox: " . $data['mailbox']['name'] . "\n";
        }

        foreach ($data['customFields'] ?? [] as $field) {
            if (($field['value'] ?? null) !== null && $field['value'] !== '') {
                $output .= $field['name'] . ": " . $field['value'] . "\n";
            }
        }

        $tags = [];
        foreach ($data['_embedded']['tags'] ?? [] as $tag) {
            $tags[] = $tag['name'] ?? '';
        }
        if (!empty($tags)) {
            $output .= "Tags: " . implode(', ', $tags) . "\n";
        }

        $output .= "\n";

        foreach ($data['_embedded']['threads'] ?? [] as $thread) {
            $type = $thread['type'] ?? 'unknown';
            if ($type === 'note' && !$includeNotes) {
                continue;
            }

            $output .= "--- MESSAGE ---\n";
            $output .= "From: " . ($thread['createdBy']['email'] ?? 'Unknown') . "\n";
            $output .= "Date: " . ($thread['createdAt'] ?? 'Unknown') . "\n";
            $output .= "Type: " . $type . "\n";

            $to = $thread['to'] ?? [];
            if (!empty($to)) {
                $output .= "To: " . implode(', ', $to) . "\n";
            }

            $output .= "\n";
            $output .= $this->htmlToPlainText($thread['body'] ?? '');
            $output .= "\n\n";
        }

        return $output;
    }

    /**
     * Get conversation in structured convo format (YAML-style for AI).
     *
     * @return array<string, mixed>
     */
    public function getConversationConvo(string $accountKey, int $conversationId): array
    {
        $data = $this->getConversationRaw($accountKey, $conversationId);

        $statusMap = [
            'active' => 'open',
            'pending' => 'pending',
            'closed' => 'closed',
            'spam' => 'spam',
        ];

        $convo = [
            'id' => (string) ($data['id'] ?? $conversationId),
            'subject' => $data['subject'] ?? '',
            'status' => $statusMap[$data['status'] ?? ''] ?? ($data['status'] ?? null),
            'channel' => 'email',
            'created_at' => $data['createdAt'] ?? null,
            'updated_at' => $data['updatedAt'] ?? null,
            'closed_at' => $data['closedAt'] ?? null,
        ];

        $metadata = [];
        if (!empty($data['mailbox']['name'])) {
            $metadata['mailbox'] = $data['mailbox']['name'];
        }
        foreach ($data['customFields'] ?? [] as $field) {
            if (isset($field['name'], $field['value']) && $field['value'] !== null && $field['value'] !== '') {
                $metadata[$field['name']] = $field['value'];
            }
        }
        foreach ($data['_embedded']['tags'] ?? [] as $tag) {
            $metadata['tags'][] = $tag['name'] ?? '';
        }
        if (!empty($metadata)) {
            $convo['metadata'] = $metadata;
        }

        $participantsById = [];

        if (!empty($data['customer']['email'])) {
            $email = $data['customer']['email'];
            $participantsById[$email] = [
                'id' => $email,
                'name' => trim(($data['customer']['firstName'] ?? '') . ' ' . ($data['customer']['lastName'] ?? '')),
                'role' => 'customer',
                'email' => $email,
            ];
        }
        if (!empty($data['assignee']['email'])) {
            $email = $data['assignee']['email'];
            if (!isset($participantsById[$email])) {
                $participantsById[$email] = [
                    'id' => $email,
                    'name' => trim(($data['assignee']['firstName'] ?? '') . ' ' . ($data['assignee']['lastName'] ?? '')),
                    'role' => 'agent',
                    'email' => $email,
                ];
            }
        }

        foreach ($data['_embedded']['threads'] ?? [] as $thread) {
            if (!empty($thread['createdBy']['email'])) {
                $email = $thread['createdBy']['email'];
                if (!isset($participantsById[$email])) {
                    $participantsById[$email] = [
                        'id' => $email,
                        'name' => trim(($thread['createdBy']['firstName'] ?? '') . ' ' . ($thread['createdBy']['lastName'] ?? '')),
                        'role' => ($thread['type'] ?? '') === 'customer' ? 'customer' : 'agent',
                        'email' => $email,
                    ];
                }
            }
        }

        $convo['participants'] = array_values($participantsById);

        $typeMap = [
            'customer' => 'message',
            'message' => 'message',
            'note' => 'internal',
            'lineitem' => 'system',
        ];

        $messages = [];
        foreach ($data['_embedded']['threads'] ?? [] as $thread) {
            $messages[] = [
                'participant' => $thread['createdBy']['email'] ?? null,
                'type' => $typeMap[$thread['type'] ?? 'message'] ?? ($thread['type'] ?? 'message'),
                'created_at' => $thread['createdAt'] ?? null,
                'message' => $this->htmlToPlainText($thread['body'] ?? ''),
            ];
        }

        $convo['messages'] = $messages;

        return $convo;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listUsers(string $accountKey): array
    {
        $allUsers = [];
        $page = 1;

        do {
            $data = $this->request($accountKey, 'GET', 'users', ['page' => $page]);

            $users = $data['_embedded']['users'] ?? $data['data'] ?? [];
            foreach ($users as $user) {
                $allUsers[] = [
                    'id' => $user['id'] ?? null,
                    'email' => $user['email'] ?? null,
                    'firstName' => $user['firstName'] ?? null,
                    'lastName' => $user['lastName'] ?? null,
                    'role' => $user['role'] ?? null,
                ];
            }

            $hasNextPage = isset($data['page']['totalPages']) && $page < $data['page']['totalPages'];
            $page++;
        } while ($hasNextPage && $page <= 50);

        return $allUsers;
    }

    /**
     * Create a thread (reply, note, or draft) on a conversation.
     *
     * @return array<string, mixed>
     */
    public function createThread(
        string $accountKey,
        int $conversationId,
        string $text,
        string $type = 'message',
        ?string $state = null,
        ?string $status = null,
        ?int $userId = null,
    ): array {
        $body = [
            'type' => $type,
            'text' => $text,
        ];

        if ($state !== null) {
            $body['state'] = $state;
        }
        if ($status !== null) {
            $body['status'] = $status;
        }
        if ($userId !== null) {
            $body['user'] = $userId;
        }

        return $this->request($accountKey, 'POST', "conversations/{$conversationId}/threads", [], $body);
    }

    /**
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

        if ($account->baseUrl === '' || $account->apiKey === '') {
            throw new \RuntimeException(sprintf(
                'Freescout account "%s" is missing base_url or api_key',
                $accountKey,
            ));
        }

        $url = $account->baseUrl . '/api/' . ltrim($endpoint, '/');

        $options = [
            'headers' => [
                'X-FreeScout-API-Key' => $account->apiKey,
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
                'Freescout API error (HTTP %d): %s',
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
