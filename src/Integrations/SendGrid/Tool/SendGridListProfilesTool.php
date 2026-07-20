<?php

namespace App\Integrations\SendGrid\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\SendGrid\SendGridService;

class SendGridListProfilesTool implements ToolInterface
{
    public function __construct(
        private readonly SendGridService $sendGridService,
    ) {
    }

    public function getName(): string
    {
        return 'sendgrid_list_profiles';
    }

    public function getDescription(): string
    {
        return 'List configured SendGrid profiles. Returns profile keys, labels and base URL (api.sendgrid.com or api.eu.sendgrid.com). Use the profile key in other SendGrid tools to choose which profile to query. If only one profile is configured, the profile argument can be omitted elsewhere.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'sendgrid';
    }

    public function execute(array $arguments): array
    {
        try {
            $profiles = $this->sendGridService->listProfiles();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($profiles),
                    'profiles' => $profiles,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing SendGrid profiles: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
