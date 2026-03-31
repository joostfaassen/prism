<?php

namespace App\Mcp\Tool;

use App\Cyans\CyansService;

class CyansGetTopicDetailsTool implements ToolInterface
{
    public function __construct(
        private readonly CyansService $cyansService,
    ) {
    }

    public function getName(): string
    {
        return 'cyans_get_topic_details';
    }

    public function getDescription(): string
    {
        return 'Get full details of a Cyans topic by its ID, including all messages, participants, and metadata.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'topic_id' => [
                    'type' => 'string',
                    'description' => 'The topic ID (xuid)',
                ],
            ],
            'required' => ['topic_id'],
        ];
    }

    public function execute(array $arguments): array
    {
        $topicId = $arguments['topic_id'] ?? '';

        if ($topicId === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "topic_id" is required']],
                'isError' => true,
            ];
        }

        try {
            $topic = $this->cyansService->getTopicDetails($topicId);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $topic,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching topic details: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
