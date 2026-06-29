<?php

namespace App\Mcp\Tool;

use App\Instagram\InstagramService;

class InstagramManageCommentTool implements ToolInterface
{
    public function __construct(
        private readonly InstagramService $instagramService,
    ) {
    }

    public function getName(): string
    {
        return 'instagram_manage_comment';
    }

    public function getDescription(): string
    {
        return 'Moderate a comment on your media. Action is one of "hide" (hide from public view), "unhide" '
            . '(restore), or "delete" (permanently remove). Useful for keeping comment sections clean and on-brand.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => ['type' => 'string', 'description' => 'Instagram account key. Optional if only one is configured.'],
                'comment_id' => ['type' => 'string', 'description' => 'The comment id to moderate.'],
                'action' => [
                    'type' => 'string',
                    'description' => 'One of: hide, unhide, delete.',
                    'enum' => ['hide', 'unhide', 'delete'],
                ],
            ],
            'required' => ['comment_id', 'action'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'instagram';
    }

    public function execute(array $arguments): array
    {
        $commentId = trim((string) ($arguments['comment_id'] ?? ''));
        $action = strtolower(trim((string) ($arguments['action'] ?? '')));

        if ($commentId === '' || !in_array($action, ['hide', 'unhide', 'delete'], true)) {
            return [
                'content' => [['type' => 'text', 'text' => 'Provide "comment_id" and an "action" of hide, unhide, or delete.']],
                'isError' => true,
            ];
        }

        try {
            $account = $arguments['account'] ?? null;
            $result = match ($action) {
                'hide' => $this->instagramService->setCommentHidden($account, $commentId, true),
                'unhide' => $this->instagramService->setCommentHidden($account, $commentId, false),
                'delete' => $this->instagramService->deleteComment($account, $commentId),
            };

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'action' => $action,
                    'comment_id' => $commentId,
                    'result' => $result,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error moderating Instagram comment: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
