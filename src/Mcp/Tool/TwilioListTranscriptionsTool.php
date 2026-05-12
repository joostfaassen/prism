<?php

namespace App\Mcp\Tool;

use App\Config\ServerContext;
use App\Twilio\TranscriptionStore;

class TwilioListTranscriptionsTool implements ToolInterface
{
    public function __construct(
        private readonly TranscriptionStore $transcriptionStore,
        private readonly ServerContext $serverContext,
    ) {
    }

    public function getName(): string
    {
        return 'twilio_list_transcriptions';
    }

    public function getDescription(): string
    {
        return 'List available call transcriptions for a Twilio account. Transcriptions are created by the twilio:transcribe-calls console command. Supports text search across transcription content and phone numbers.';
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
                'search' => [
                    'type' => 'string',
                    'description' => 'Search text to filter transcriptions (matches against transcription text, from/to numbers)',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max results to return (default 50)',
                ],
            ],
            'required' => ['account'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'twilio';
    }

    public function execute(array $arguments): array
    {
        $accountKey = $arguments['account'] ?? '';
        if ($accountKey === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Missing required parameter: account']],
                'isError' => true,
            ];
        }

        try {
            $serverName = $this->serverContext->getServerName();
            $search = $arguments['search'] ?? null;
            $limit = $arguments['limit'] ?? 50;

            $transcriptions = $this->transcriptionStore->list($serverName, $accountKey, $search, $limit);

            $summaries = array_map(fn(array $t) => [
                'call_sid' => $t['call_sid'],
                'from' => $t['from'],
                'to' => $t['to'],
                'direction' => $t['direction'],
                'duration' => $t['duration'],
                'start_time' => $t['start_time'],
                'transcription_preview' => mb_substr($t['transcription'] ?? '', 0, 200),
                'transcribed_at' => $t['transcribed_at'] ?? null,
            ], $transcriptions);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($summaries),
                    'transcriptions' => $summaries,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
