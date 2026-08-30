<?php

namespace App\Integrations\Bunq\Tool;

use App\Integrations\Bunq\BunqService;
use App\Mcp\Tool\ToolInterface;

class BunqGetTransactionAttachmentTool implements ToolInterface
{
    private const IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function __construct(
        private readonly BunqService $bunqService,
    ) {
    }

    public function getName(): string
    {
        return 'bunq_get_transaction_attachment';
    }

    public function getDescription(): string
    {
        return 'Download a bunq transaction attachment (photo, receipt, or other file). '
            . 'Use an attachment_id from bunq_get_transaction. '
            . 'Images are returned as MCP image content; other files as base64 JSON.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'attachment_id' => [
                    'type' => 'integer',
                    'description' => 'The bunq attachment ID from bunq_get_transaction.attachment_ids',
                ],
                'monetary_account_id' => [
                    'type' => 'integer',
                    'description' => 'The monetary account ID the attachment belongs to. Recommended.',
                ],
                'profile' => [
                    'type' => 'string',
                    'description' => 'bunq profile key. Optional if only one profile is configured.',
                ],
            ],
            'required' => ['attachment_id'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'bunq';
    }

    public function execute(array $arguments): array
    {
        $attachmentId = $arguments['attachment_id'] ?? null;
        if ($attachmentId === null || $attachmentId === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "attachment_id" is required']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->bunqService->getAttachmentContent(
                attachmentId: (int) $attachmentId,
                monetaryAccountId: isset($arguments['monetary_account_id'])
                    ? (int) $arguments['monetary_account_id']
                    : null,
                profileKey: isset($arguments['profile']) && $arguments['profile'] !== ''
                    ? (string) $arguments['profile']
                    : null,
            );
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error downloading attachment: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }

        $base64 = base64_encode($result['content']);
        $meta = json_encode([
            'attachment_id' => $result['attachment_id'],
            'monetary_account_id' => $result['monetary_account_id'],
            'mime_type' => $result['mime_type'],
            'bytes' => $result['bytes'],
            'source' => $result['source'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        if (in_array($result['mime_type'], self::IMAGE_MIME_TYPES, true)) {
            return [
                'content' => [
                    [
                        'type' => 'image',
                        'data' => $base64,
                        'mimeType' => $result['mime_type'] === 'image/jpg' ? 'image/jpeg' : $result['mime_type'],
                    ],
                    [
                        'type' => 'text',
                        'text' => $meta,
                    ],
                ],
            ];
        }

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'attachment_id' => $result['attachment_id'],
                    'monetary_account_id' => $result['monetary_account_id'],
                    'mime_type' => $result['mime_type'],
                    'bytes' => $result['bytes'],
                    'source' => $result['source'],
                    'encoding' => 'base64',
                    'base64' => $base64,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]],
        ];
    }
}
