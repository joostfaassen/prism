<?php

namespace App\Command;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;
use App\Twilio\TranscriptionStore;
use App\Twilio\TwilioService;
use App\Whisper\WhisperService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'twilio:transcribe-calls',
    description: 'Fetch Twilio call recordings and transcribe them via Whisper',
)]
class TwilioTranscribeCallsCommand extends Command
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
        private readonly TwilioService $twilioService,
        private readonly WhisperService $whisperService,
        private readonly TranscriptionStore $transcriptionStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('server', InputArgument::REQUIRED, 'Server name from prism.config.yaml')
            ->addArgument('account', InputArgument::REQUIRED, 'Twilio account key')
            ->addOption('call-sid', null, InputOption::VALUE_REQUIRED, 'Transcribe a specific call by SID')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max number of recent calls to process', '20')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter calls by status', 'completed')
            ->addOption('skip-existing', null, InputOption::VALUE_NONE, 'Skip calls that already have transcriptions')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Language hint for Whisper (ISO 639-1)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $serverName = $input->getArgument('server');
        $accountKey = $input->getArgument('account');

        try {
            $server = $this->configLoader->getServer($serverName);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->serverContext->setServer($server);

        if (!$this->whisperService->isConfigured()) {
            $io->error('Whisper is not configured. Add a "whisper:" section to prism.config.yaml');

            return Command::FAILURE;
        }

        $io->title(sprintf('Twilio Call Transcription — %s / %s', $serverName, $accountKey));
        $io->info(sprintf('Whisper provider: %s', $this->whisperService->getProviderName()));

        $callSid = $input->getOption('call-sid');
        $skipExisting = $input->getOption('skip-existing');
        $language = $input->getOption('language');

        if ($callSid !== null) {
            $calls = [$this->twilioService->getCall($accountKey, $callSid)];
        } else {
            $limit = (int) $input->getOption('limit');
            $status = $input->getOption('status');

            $io->info(sprintf('Fetching up to %d %s calls...', $limit, $status));

            $result = $this->twilioService->listCalls($accountKey, [
                'limit' => $limit,
                'status' => $status,
            ]);
            $calls = $result['calls'];
        }

        $io->info(sprintf('Found %d calls', count($calls)));

        $transcribed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($calls as $call) {
            $sid = $call['sid'];
            $from = $call['from_formatted'] ?? $call['from'] ?? 'unknown';
            $to = $call['to_formatted'] ?? $call['to'] ?? 'unknown';
            $duration = $call['duration'] ?? '0';

            $io->section(sprintf('Call %s (%s → %s, %ss)', $sid, $from, $to, $duration));

            if ($skipExisting && $this->transcriptionStore->exists($serverName, $accountKey, $sid)) {
                $io->comment('Transcription already exists, skipping');
                $skipped++;
                continue;
            }

            try {
                $recordings = $this->twilioService->listRecordings($accountKey, $sid);
            } catch (\Throwable $e) {
                $io->warning('Failed to fetch recordings: ' . $e->getMessage());
                $errors++;
                continue;
            }

            if (empty($recordings)) {
                $io->comment('No recordings found');
                $skipped++;
                continue;
            }

            $allTranscriptions = [];

            foreach ($recordings as $recording) {
                $recordingSid = $recording['sid'];
                $io->text(sprintf('  Recording %s (%ss)', $recordingSid, $recording['duration'] ?? '?'));

                $tempFile = sys_get_temp_dir() . '/prism_twilio_' . $recordingSid . '.mp3';

                try {
                    $io->text('  Downloading audio...');
                    $this->twilioService->downloadRecording($accountKey, $recordingSid, $tempFile);

                    $io->text('  Transcribing...');
                    $result = $this->whisperService->transcribe($tempFile, $language);

                    $allTranscriptions[] = [
                        'recording_sid' => $recordingSid,
                        'text' => $result->text,
                        'language' => $result->language,
                        'duration' => $result->duration,
                    ];

                    $preview = mb_substr($result->text, 0, 120);
                    $io->success(sprintf('  "%s%s"', $preview, mb_strlen($result->text) > 120 ? '...' : ''));
                } catch (\Throwable $e) {
                    $io->error('  Transcription failed: ' . $e->getMessage());
                    $errors++;
                } finally {
                    if (file_exists($tempFile)) {
                        unlink($tempFile);
                    }
                }
            }

            if (!empty($allTranscriptions)) {
                $combinedText = implode("\n\n", array_map(fn($t) => $t['text'], $allTranscriptions));

                $this->transcriptionStore->save($serverName, $accountKey, $sid, [
                    'call_sid' => $sid,
                    'from' => $call['from'] ?? '',
                    'to' => $call['to'] ?? '',
                    'direction' => $call['direction'] ?? '',
                    'status' => $call['status'] ?? '',
                    'duration' => $duration,
                    'start_time' => $call['start_time'] ?? '',
                    'end_time' => $call['end_time'] ?? '',
                    'transcription' => $combinedText,
                    'recordings' => $allTranscriptions,
                    'transcribed_at' => (new \DateTimeImmutable())->format('c'),
                    'whisper_provider' => $this->whisperService->getProviderName(),
                ]);

                $transcribed++;
            }
        }

        $io->newLine();
        $io->definitionList(
            ['Transcribed' => $transcribed],
            ['Skipped' => $skipped],
            ['Errors' => $errors],
        );

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
