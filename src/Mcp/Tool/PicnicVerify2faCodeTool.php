<?php

namespace App\Mcp\Tool;

use App\Picnic\PicnicService;

class PicnicVerify2faCodeTool implements ToolInterface
{
    public function __construct(
        private readonly PicnicService $picnicService,
    ) {
    }

    public function getName(): string
    {
        return 'picnic_verify_2fa_code';
    }

    public function getDescription(): string
    {
        return 'Verify a Picnic SMS 2FA code and cache the new auth session. '
            . 'Call picnic_generate_2fa_code first when login requires 2FA.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'code' => [
                    'type' => 'string',
                    'description' => 'The OTP / SMS code',
                ],
                'account' => [
                    'type' => 'string',
                    'description' => 'Picnic account key. Defaults to the first configured account.',
                ],
            ],
            'required' => ['code'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'picnic';
    }

    public function execute(array $arguments): array
    {
        $code = trim((string) ($arguments['code'] ?? ''));
        $account = $arguments['account'] ?? null;

        if ($code === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "code" is required']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->picnicService->verify2faCode($code, $account);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error verifying Picnic 2FA code: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
