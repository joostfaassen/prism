<?php

namespace App\N8n;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class N8nService
{
    public function __construct(
        private readonly N8nConfigLoader $configLoader,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return list<array{key: string, label: string, base_url: string}>
     */
    public function listAccounts(): array
    {
        $accounts = [];
        foreach ($this->configLoader->getAccounts() as $key => $account) {
            $accounts[] = [
                'key' => $key,
                'label' => $account->label,
                'base_url' => $account->baseUrl,
            ];
        }

        return $accounts;
    }

    /**
     * List workflows (flows). Returns metadata only — use getWorkflow() to read the
     * full node/connection definition of a single flow.
     *
     * @return array{data: list<array<string, mixed>>, nextCursor: string|null}
     */
    public function listWorkflows(
        ?string $accountKey = null,
        ?bool $active = null,
        ?string $name = null,
        ?string $tags = null,
        int $limit = 50,
        ?string $cursor = null,
    ): array {
        $query = [
            'limit' => $limit,
            'excludePinnedData' => 'true',
        ];

        if ($active !== null) {
            $query['active'] = $active ? 'true' : 'false';
        }
        if ($name !== null && $name !== '') {
            $query['name'] = $name;
        }
        if ($tags !== null && $tags !== '') {
            $query['tags'] = $tags;
        }
        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }

        $data = $this->request($accountKey, 'GET', '/workflows', $query);

        $workflows = [];
        foreach ($data['data'] ?? [] as $workflow) {
            if (!is_array($workflow)) {
                continue;
            }
            $workflows[] = $this->summarizeWorkflow($workflow);
        }

        return [
            'data' => $workflows,
            'nextCursor' => $data['nextCursor'] ?? null,
        ];
    }

    /**
     * Get a single workflow including its full definition (nodes, connections,
     * settings). This is the "download" of a flow — the returned `definition`
     * is the complete JSON that can be re-imported into n8n.
     *
     * @return array<string, mixed>
     */
    public function getWorkflow(?string $accountKey, string $id): array
    {
        $data = $this->request($accountKey, 'GET', '/workflows/' . rawurlencode($id));

        return [
            'summary' => $this->summarizeWorkflow($data),
            'definition' => $data,
        ];
    }

    /**
     * List executions (runs). Returns metadata by default.
     *
     * @return array{data: list<array<string, mixed>>, nextCursor: string|null}
     */
    public function listExecutions(
        ?string $accountKey = null,
        ?string $workflowId = null,
        ?string $status = null,
        bool $includeData = false,
        int $limit = 25,
        ?string $cursor = null,
    ): array {
        $query = [
            'limit' => $limit,
            'includeData' => $includeData ? 'true' : 'false',
        ];

        if ($workflowId !== null && $workflowId !== '') {
            $query['workflowId'] = $workflowId;
        }
        if ($status !== null && $status !== '') {
            $query['status'] = $status;
        }
        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }

        $data = $this->request($accountKey, 'GET', '/executions', $query);

        $executions = [];
        foreach ($data['data'] ?? [] as $execution) {
            if (!is_array($execution)) {
                continue;
            }
            $executions[] = $includeData ? $execution : $this->summarizeExecution($execution);
        }

        return [
            'data' => $executions,
            'nextCursor' => $data['nextCursor'] ?? null,
        ];
    }

    /**
     * Get a single execution (run). Set includeData=true to include the full
     * run data (node inputs/outputs), which can be large.
     *
     * @return array<string, mixed>
     */
    public function getExecution(?string $accountKey, string $id, bool $includeData = false): array
    {
        $data = $this->request($accountKey, 'GET', '/executions/' . rawurlencode($id), [
            'includeData' => $includeData ? 'true' : 'false',
        ]);

        if ($includeData) {
            return $data;
        }

        return $this->summarizeExecution($data);
    }

    /**
     * @param array<string, mixed> $workflow
     *
     * @return array<string, mixed>
     */
    private function summarizeWorkflow(array $workflow): array
    {
        $tags = [];
        foreach ($workflow['tags'] ?? [] as $tag) {
            if (is_array($tag)) {
                $tags[] = $tag['name'] ?? $tag['id'] ?? null;
            } elseif (is_scalar($tag)) {
                $tags[] = $tag;
            }
        }

        return [
            'id' => $workflow['id'] ?? null,
            'name' => $workflow['name'] ?? null,
            'active' => $workflow['active'] ?? null,
            'tags' => array_values(array_filter($tags, static fn ($t) => $t !== null)),
            'node_count' => is_array($workflow['nodes'] ?? null) ? count($workflow['nodes']) : null,
            'created_at' => $workflow['createdAt'] ?? null,
            'updated_at' => $workflow['updatedAt'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $execution
     *
     * @return array<string, mixed>
     */
    private function summarizeExecution(array $execution): array
    {
        return [
            'id' => $execution['id'] ?? null,
            'workflow_id' => $execution['workflowId'] ?? null,
            'status' => $execution['status'] ?? null,
            'mode' => $execution['mode'] ?? null,
            'finished' => $execution['finished'] ?? null,
            'retry_of' => $execution['retryOf'] ?? null,
            'started_at' => $execution['startedAt'] ?? null,
            'stopped_at' => $execution['stoppedAt'] ?? null,
            'wait_till' => $execution['waitTill'] ?? null,
        ];
    }

    private function resolveAccount(?string $accountKey): N8nAccountConfig
    {
        if ($accountKey !== null && $accountKey !== '') {
            return $this->configLoader->getAccount($accountKey);
        }

        $accounts = $this->configLoader->getAccounts();
        if (empty($accounts)) {
            throw new \RuntimeException('No n8n accounts configured for this server');
        }

        return reset($accounts);
    }

    /**
     * @param array<string, scalar|null> $query
     *
     * @return array<string, mixed>
     */
    private function request(?string $accountKey, string $method, string $path, array $query = []): array
    {
        $account = $this->resolveAccount($accountKey);

        if ($account->baseUrl === '' || $account->apiKey === '') {
            throw new \RuntimeException(sprintf(
                'n8n account "%s" is missing base_url or api_key',
                $account->key,
            ));
        }

        $filteredQuery = [];
        foreach ($query as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $filteredQuery[$name] = $value;
        }

        $response = $this->httpClient->request($method, $account->baseUrl . '/api/v1' . $path, [
            'headers' => [
                'X-N8N-API-KEY' => $account->apiKey,
                'Accept' => 'application/json',
            ],
            'query' => $filteredQuery,
            'timeout' => 30,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'n8n API error (HTTP %d): %s',
                $statusCode,
                $response->getContent(false),
            ));
        }

        $content = $response->getContent();
        if ($content === '') {
            return [];
        }

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : ['value' => $data];
    }
}
