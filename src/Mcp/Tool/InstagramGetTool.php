<?php

namespace App\Mcp\Tool;

use App\Instagram\InstagramService;

class InstagramGetTool implements ToolInterface
{
    public function __construct(
        private readonly InstagramService $instagramService,
    ) {
    }

    public function getName(): string
    {
        return 'instagram_get';
    }

    public function getDescription(): string
    {
        return 'Run an arbitrary read-only Meta Graph API GET against any Instagram node or edge for advanced/ad-hoc '
            . 'queries not covered by the dedicated tools (e.g. "{media-id}/comments", "{ig-user-id}/tags", '
            . '"{ig-user-id}/stories", "{ig-user-id}/live_media"). The account\'s access token, version prefix and '
            . 'appsecret_proof are added automatically — pass the path WITHOUT a leading version or access_token. '
            . 'Use the "params" object for query parameters like fields, limit, after, since, until.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => ['type' => 'string', 'description' => 'Instagram account key. Optional if only one is configured.'],
                'path' => ['type' => 'string', 'description' => 'Graph API node/edge path, e.g. "me/accounts" or "{ig-user-id}/stories". No version prefix.'],
                'params' => [
                    'type' => 'object',
                    'description' => 'Optional query parameters as key/value pairs (e.g. {"fields":"id,caption","limit":10}). Values must be strings, numbers or booleans.',
                    'additionalProperties' => true,
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'instagram';
    }

    public function execute(array $arguments): array
    {
        $path = trim((string) ($arguments['path'] ?? ''));
        if ($path === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'The "path" argument is required, e.g. "{ig-user-id}/stories".']],
                'isError' => true,
            ];
        }

        $params = [];
        if (isset($arguments['params']) && is_array($arguments['params'])) {
            foreach ($arguments['params'] as $name => $value) {
                if (is_scalar($value)) {
                    $params[(string) $name] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
                }
            }
        }

        try {
            $result = $this->instagramService->rawGet($arguments['account'] ?? null, $path, $params);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'path' => $path,
                    'result' => $result,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error running Instagram Graph API GET: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
