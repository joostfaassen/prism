<?php

namespace App\Mcp\Tool;

use App\Instagram\InstagramService;

class InstagramReplyCommentTool implements ToolInterface
{
    public function __construct(
        private readonly InstagramService $instagramService,
    ) {
    }

    public function getName(): string
    {
        return 'instagram_reply_comment';
    }

    public function getDescription(): string
    {
        return 'Post a comment to boost engagement. Provide a comment_id to reply to an existing comment (threaded), '
            . 'OR a media_id to post a new top-level comment on a post/reel. Exactly one of comment_id or media_id '
            . 'is required, plus the message text. This writes publicly as your account.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => ['type' => 'string', 'description' => 'Instagram account key. Optional if only one is configured.'],
                'comment_id' => ['type' => 'string', 'description' => 'Reply to this comment id (threaded reply).'],
                'media_id' => ['type' => 'string', 'description' => 'Post a new top-level comment on this media id.'],
                'message' => ['type' => 'string', 'description' => 'The comment text.'],
            ],
            'required' => ['message'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'instagram';
    }

    public function execute(array $arguments): array
    {
        $message = trim((string) ($arguments['message'] ?? ''));
        $commentId = trim((string) ($arguments['comment_id'] ?? ''));
        $mediaId = trim((string) ($arguments['media_id'] ?? ''));

        if ($message === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'The "message" argument is required.']],
                'isError' => true,
            ];
        }
        if (($commentId === '') === ($mediaId === '')) {
            return [
                'content' => [['type' => 'text', 'text' => 'Provide exactly one of "comment_id" (reply) or "media_id" (new comment).']],
                'isError' => true,
            ];
        }

        try {
            $result = $commentId !== ''
                ? $this->instagramService->replyToComment($arguments['account'] ?? null, $commentId, $message)
                : $this->instagramService->commentOnMedia($arguments['account'] ?? null, $mediaId, $message);

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error posting Instagram comment: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
