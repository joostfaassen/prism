<?php

namespace App\Integrations\Email\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Email\EmailService;

class EmailListProfilesTool implements ToolInterface
{
    public function __construct(
        private readonly EmailService $emailService,
    ) {
    }

    public function getName(): string
    {
        return 'email_list_profiles';
    }

    public function getDescription(): string
    {
        return 'List all configured email profiles (IMAP for reading, SMTP for sending). Each profile reports whether it can send mail, the From address it will use, and the configured Sent folder.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function getProfileType(): ?string
    {
        return 'email';
    }

    public function execute(array $arguments): array
    {
        try {
            $profiles = $this->emailService->listProfiles();

            return [
                'content' => [['type' => 'text', 'text' => json_encode(['profiles' => $profiles], JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing profiles: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
