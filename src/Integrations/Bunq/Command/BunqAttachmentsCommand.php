<?php

namespace App\Integrations\Bunq\Command;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;
use App\Integrations\Bunq\BunqService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'bunq:attachments',
    description: 'Scan, inspect, or download bunq transaction notes and file attachments',
)]
class BunqAttachmentsCommand extends Command
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
        private readonly BunqService $bunqService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'scan | show | download | raw')
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server name from prism config')
            ->addOption('bunq-profile', null, InputOption::VALUE_REQUIRED, 'bunq profile key (default: first, or * for scan)')
            ->addOption('payment-id', null, InputOption::VALUE_REQUIRED, 'Payment id (show)')
            ->addOption('monetary-account-id', null, InputOption::VALUE_REQUIRED, 'Monetary account id')
            ->addOption('attachment-id', null, InputOption::VALUE_REQUIRED, 'Attachment id (download)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max payments/card actions per account (scan)', '20')
            ->addOption('date-from', null, InputOption::VALUE_REQUIRED, 'Inclusive start date YYYY-MM-DD (scan)')
            ->addOption('date-to', null, InputOption::VALUE_REQUIRED, 'Inclusive end date YYYY-MM-DD (scan)')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Output file path (download)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = strtolower(trim((string) $input->getArgument('action')));
        $serverName = trim((string) ($input->getOption('server') ?? ''));

        if ($serverName === '') {
            $io->error('Option "--server" is required.');

            return Command::INVALID;
        }

        try {
            $server = $this->configLoader->getServer($serverName);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->serverContext->setServer($server);

        return match ($action) {
            'scan' => $this->scan($input, $io),
            'show' => $this->show($input, $io),
            'raw' => $this->raw($input, $io),
            'download' => $this->download($input, $io),
            default => $this->unknownAction($io, $action),
        };
    }

    private function unknownAction(SymfonyStyle $io, string $action): int
    {
        $io->error(sprintf('Unknown action "%s". Use scan, show, download, or raw.', $action));

        return Command::INVALID;
    }

    private function scan(InputInterface $input, SymfonyStyle $io): int
    {
        $profile = trim((string) ($input->getOption('bunq-profile') ?? ''));
        if ($profile === '') {
            $profile = '*';
        }

        $limit = max(1, (int) $input->getOption('limit'));
        $io->title('bunq attachment scan');
        $io->writeln(sprintf(
            'Profile: <info>%s</info> | Limit per account: <info>%d</info>',
            $profile,
            $limit,
        ));

        try {
            $hits = $this->bunqService->scanTransactionAttachments(
                profilesParam: $profile,
                limit: $limit,
                dateFrom: $this->optionalString($input, 'date-from'),
                dateTo: $this->optionalString($input, 'date-to'),
                monetaryAccountId: $this->optionalInt($input, 'monetary-account-id'),
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($hits === []) {
            $io->warning('No notes or attachments found in the scanned window.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($hits as $hit) {
            $rows[] = [
                $hit['source'] ?? '',
                $hit['payment_id'] ?? $hit['mastercard_action_id'] ?? '',
                $hit['monetary_account_id'] ?? '',
                $hit['amount'] ?? '',
                $this->oneLine((string) ($hit['counterparty_name'] ?? '')),
                (int) ($hit['text_note_count'] ?? 0),
                (int) ($hit['attachment_count'] ?? 0),
                (int) ($hit['payment_attachment_count'] ?? 0),
                $hit['errors'] === [] ? '' : implode(' | ', $hit['errors']),
            ];
        }

        $io->table(
            ['source', 'id', 'account', 'amount', 'counterparty', 'notes', 'att', 'pay-att', 'errors'],
            $rows,
        );

        $io->writeln(json_encode($hits, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        return Command::SUCCESS;
    }

    private function show(InputInterface $input, SymfonyStyle $io): int
    {
        $paymentId = $input->getOption('payment-id');
        if ($paymentId === null || $paymentId === '') {
            $io->error('Option "--payment-id" is required for show.');

            return Command::INVALID;
        }

        try {
            $result = $this->bunqService->getTransaction(
                paymentId: (int) $paymentId,
                monetaryAccountId: $this->optionalInt($input, 'monetary-account-id'),
                profileKey: $this->optionalString($input, 'bunq-profile'),
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        return ($result['errors'] ?? []) === [] ? Command::SUCCESS : Command::FAILURE;
    }

    private function raw(InputInterface $input, SymfonyStyle $io): int
    {
        $paymentId = $input->getOption('payment-id');
        $accountId = $this->optionalInt($input, 'monetary-account-id');
        if ($paymentId === null || $paymentId === '' || $accountId === null) {
            $io->error('Options "--payment-id" and "--monetary-account-id" are required for raw.');

            return Command::INVALID;
        }

        try {
            $result = $this->bunqService->inspectPaymentRaw(
                paymentId: (int) $paymentId,
                monetaryAccountId: $accountId,
                profileKey: $this->optionalString($input, 'bunq-profile'),
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        return Command::SUCCESS;
    }

    private function download(InputInterface $input, SymfonyStyle $io): int
    {
        $attachmentId = $input->getOption('attachment-id');
        if ($attachmentId === null || $attachmentId === '') {
            $io->error('Option "--attachment-id" is required for download.');

            return Command::INVALID;
        }

        $out = trim((string) ($input->getOption('out') ?? ''));
        if ($out === '') {
            $out = sprintf('/tmp/bunq-attachment-%d.bin', (int) $attachmentId);
        }

        try {
            $result = $this->bunqService->getAttachmentContent(
                attachmentId: (int) $attachmentId,
                monetaryAccountId: $this->optionalInt($input, 'monetary-account-id'),
                profileKey: $this->optionalString($input, 'bunq-profile'),
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $dir = dirname($out);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            $io->error('Could not create output directory: ' . $dir);

            return Command::FAILURE;
        }

        if (file_put_contents($out, $result['content']) === false) {
            $io->error('Could not write ' . $out);

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Wrote %s (%s, %d bytes, source=%s) to %s',
            $result['attachment_id'],
            $result['mime_type'],
            $result['bytes'],
            $result['source'],
            $out,
        ));

        return Command::SUCCESS;
    }

    private function optionalString(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function optionalInt(InputInterface $input, string $name): ?int
    {
        $value = $input->getOption($name);
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function oneLine(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
