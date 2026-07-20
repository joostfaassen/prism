<?php

namespace App\Integrations\Twilio\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Config\ServerContext;
use App\Integrations\Twilio\TranscriptionStore;

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
        return 'List available call transcriptions for a Twilio profile. Transcriptions are created by the twilio:transcribe-calls console command. Supports text search across transcription content and phone numbers.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile' => [
                    'type' => 'string',
                    'description' => 'Twilio profile key from configuration',
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
            'required' => ['profile'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'twilio';
    }

    public function execute(array $arguments): array
    {
        $profileKey = $arguments['profile'] ?? '';
        if ($profileKey === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Missing required parameter: profile']],
                'isError' => true,
            ];
        }

        try {
            $serverName = $this->serverContext->getServerName();
            $search = $arguments['search'] ?? null;
            $limit = $arguments['limit'] ?? 50;

            $transcriptions = $this->transcriptionStore->list($serverName, $profileKey, $search, $limit);

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
