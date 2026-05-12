<?php

namespace App\Mcp\Tool;

use App\Config\ServerContext;
use App\Twilio\TranscriptionStore;

class TwilioGetTranscriptionTool implements ToolInterface
{
    public function __construct(
        private readonly TranscriptionStore $transcriptionStore,
        private readonly ServerContext $serverContext,
    ) {
    }

    public function getName(): string
    {
        return 'twilio_get_transcription';
    }

    public function getDescription(): string
    {
        return 'Get the full transcription for a specific Twilio call by its Call SID. Returns the complete transcription text, call metadata, and per-recording details.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Twilio account key from configuration',
                ],
                'call_sid' => [
                    'type' => 'string',
                    'description' => 'The Call SID to get the transcription for',
                ],
            ],
            'required' => ['account', 'call_sid'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'twilio';
    }

    public function execute(array $arguments): array
    {
        $accountKey = $arguments['account'] ?? '';
        $callSid = $arguments['call_sid'] ?? '';

        if ($accountKey === '' || $callSid === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Missing required parameters: account and call_sid']],
                'isError' => true,
            ];
        }

        try {
            $serverName = $this->serverContext->getServerName();
            $transcription = $this->transcriptionStore->get($serverName, $accountKey, $callSid);

            if ($transcription === null) {
                return [
                    'content' => [['type' => 'text', 'text' => sprintf(
                        'No transcription found for call %s. Run the twilio:transcribe-calls command first.',
                        $callSid,
                    )]],
                    'isError' => true,
                ];
            }

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $transcription,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
