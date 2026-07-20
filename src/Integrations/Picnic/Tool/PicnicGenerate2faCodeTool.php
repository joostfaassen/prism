<?php

namespace App\Integrations\Picnic\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Picnic\PicnicService;

class PicnicGenerate2faCodeTool implements ToolInterface
{
    public function __construct(
        private readonly PicnicService $picnicService,
    ) {
    }

    public function getName(): string
    {
        return 'picnic_generate_2fa_code';
    }

    public function getDescription(): string
    {
        return 'Ask Picnic to send a 2FA code (SMS by default) after login reports '
            . 'second_factor_authentication_required. Then call picnic_verify_2fa_code.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'channel' => [
                    'type' => 'string',
                    'description' => 'Delivery channel. Default SMS.',
                ],
                'profile' => [
                    'type' => 'string',
                    'description' => 'Picnic profile key. Defaults to the first configured profile.',
                ],
            ],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'picnic';
    }

    public function execute(array $arguments): array
    {
        $channel = trim((string) ($arguments['channel'] ?? 'SMS'));
        if ($channel === '') {
            $channel = 'SMS';
        }
        $profile = $arguments['profile'] ?? null;

        try {
            $result = $this->picnicService->generate2faCode($channel, $profile);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error generating Picnic 2FA code: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
