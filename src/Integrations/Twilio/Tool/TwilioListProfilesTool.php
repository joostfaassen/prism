<?php

namespace App\Integrations\Twilio\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Twilio\TwilioService;

class TwilioListProfilesTool implements ToolInterface
{
    public function __construct(
        private readonly TwilioService $twilioService,
    ) {
    }

    public function getName(): string
    {
        return 'twilio_list_profiles';
    }

    public function getDescription(): string
    {
        return 'List all configured Twilio profiles. Returns profile keys that can be used with other Twilio tools.';
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
        return 'twilio';
    }

    public function execute(array $arguments): array
    {
        try {
            $profiles = $this->twilioService->listProfiles();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'profiles' => $profiles,
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
