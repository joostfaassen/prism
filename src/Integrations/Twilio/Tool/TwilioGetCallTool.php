<?php

namespace App\Integrations\Twilio\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Twilio\TwilioService;

class TwilioGetCallTool implements ToolInterface
{
    public function __construct(
        private readonly TwilioService $twilioService,
    ) {
    }

    public function getName(): string
    {
        return 'twilio_get_call';
    }

    public function getDescription(): string
    {
        return 'Get full details for a specific Twilio call by SID, including any recordings associated with the call.';
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
                'call_sid' => [
                    'type' => 'string',
                    'description' => 'The Call SID (starts with CA)',
                ],
                'include_recordings' => [
                    'type' => 'boolean',
                    'description' => 'Also fetch recordings for this call (default true)',
                ],
            ],
            'required' => ['profile', 'call_sid'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'twilio';
    }

    public function execute(array $arguments): array
    {
        $profileKey = $arguments['profile'] ?? '';
        $callSid = $arguments['call_sid'] ?? '';

        if ($profileKey === '' || $callSid === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Missing required parameters: profile and call_sid']],
                'isError' => true,
            ];
        }

        try {
            $call = $this->twilioService->getCall($profileKey, $callSid);

            $result = [
                'sid' => $call['sid'],
                'parent_call_sid' => $call['parent_call_sid'] ?? null,
                'from' => $call['from_formatted'] ?? $call['from'],
                'from_raw' => $call['from'],
                'to' => $call['to_formatted'] ?? $call['to'],
                'to_raw' => $call['to'],
                'direction' => $call['direction'],
                'status' => $call['status'],
                'duration' => $call['duration'],
                'start_time' => $call['start_time'],
                'end_time' => $call['end_time'],
                'date_created' => $call['date_created'],
                'date_updated' => $call['date_updated'],
                'price' => $call['price'],
                'price_unit' => $call['price_unit'],
                'answered_by' => $call['answered_by'] ?? null,
                'caller_name' => $call['caller_name'] ?? null,
                'forwarded_from' => $call['forwarded_from'] ?? null,
            ];

            $includeRecordings = $arguments['include_recordings'] ?? true;
            if ($includeRecordings) {
                $recordings = $this->twilioService->listRecordings($profileKey, $callSid);
                $result['recordings'] = array_map(fn(array $r) => [
                    'sid' => $r['sid'],
                    'duration' => $r['duration'],
                    'channels' => $r['channels'] ?? 1,
                    'source' => $r['source'] ?? null,
                    'status' => $r['status'],
                    'date_created' => $r['date_created'],
                    'media_url' => $this->twilioService->getRecordingMediaUrl($profileKey, $r['sid']),
                ], $recordings);
            }

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching call: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
