<?php

namespace App\Integrations\Freescout\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Freescout\FreescoutService;

class FreescoutListMailboxesTool implements ToolInterface
{
    public function __construct(
        private readonly FreescoutService $freescoutService,
    ) {
    }

    public function getName(): string
    {
        return 'freescout_list_mailboxes';
    }

    public function getDescription(): string
    {
        return 'List mailboxes in a Freescout profile. Returns mailbox IDs, names, and email addresses. Use mailbox IDs when listing conversations.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile' => [
                    'type' => 'string',
                    'description' => 'Freescout profile key. Use freescout_list_profiles to see available profiles.',
                ],
            ],
            'required' => ['profile'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'freescout';
    }

    public function execute(array $arguments): array
    {
        $profileKey = $arguments['profile'] ?? '';
        if ($profileKey === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "profile" is required']],
                'isError' => true,
            ];
        }

        try {
            $mailboxes = $this->freescoutService->listMailboxes($profileKey);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($mailboxes),
                    'mailboxes' => $mailboxes,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
