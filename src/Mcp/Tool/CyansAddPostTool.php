<?php

namespace App\Mcp\Tool;

use App\Cyans\CyansService;

class CyansAddPostTool implements ToolInterface
{
    public function __construct(
        private readonly CyansService $cyansService,
    ) {
    }

    public function getName(): string
    {
        return 'cyans_add_post';
    }

    public function getDescription(): string
    {
        return 'Add a post (message) to an existing Cyans topic. The author defaults to the configured CYANS_USERNAME.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'topic_id' => [
                    'type' => 'string',
                    'description' => 'The topic ID (xuid) to post to',
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'The message text to post',
                ],
                'author' => [
                    'type' => 'string',
                    'description' => 'Author username. Defaults to the configured CYANS_USERNAME.',
                ],
            ],
            'required' => ['topic_id', 'message'],
        ];
    }

    public function execute(array $arguments): array
    {
        $topicId = $arguments['topic_id'] ?? '';
        $message = trim($arguments['message'] ?? '');
        $author = $arguments['author'] ?? null;

        if ($topicId === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "topic_id" is required']],
                'isError' => true,
            ];
        }

        if ($message === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "message" is required and cannot be empty']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->cyansService->addPost($topicId, $message, $author);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error adding post: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
